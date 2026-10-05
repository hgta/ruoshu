#!/usr/bin/env bash
# 若书 · 宝塔面板首次部署引导（CentOS8 + 宝塔11 + PHP 8.3）
#
# 覆盖 docs/ops/bt-deployment.md 的第 2~9 步；第 1 步（面板 UI：装软件/建站点/解禁函数）需先手工做完。
#
# 用法：
#   bash bootstrap-bt.sh --init         # 全量：目录+代码+依赖+密钥+.env模板+迁移+nginx片段+边车+systemd
#   bash bootstrap-bt.sh --env-only     # 仅铺目录 + 生成 .env 模板（供手填后再跑 --init）
#   bash bootstrap-bt.sh --update       # 后续更新：git pull + 依赖 + 迁移 + 缓存 + 重启队列
#
# 环境变量：
#   REPO=git@github.com:hgta/ruoshu.git   SKIP_AI=1（跳过 Python 边车）  SKIP_GO=1（跳过 Go 构建）
set -euo pipefail

REPO="${REPO:-git@github.com:hgta/ruoshu.git}"
ROOT="/www/wwwroot/ruoshu"
WEB="${ROOT}/current/web"
PHP_BIN="${PHP_BIN:-/www/server/php/83/bin/php}"
MODE="${1:---init}"

log()  { echo -e "\n\033[36m==>\033[0m $*"; }
warn() { echo -e "\033[33mWARN:\033[0m $*"; }
die()  { echo -e "\033[31mFAIL:\033[0m $*"; exit 1; }

[ "$(id -u)" = "0" ] || die "请用 root 执行"
[ -x "$PHP_BIN" ] || die "找不到 PHP：${PHP_BIN}（设 PHP_BIN 覆盖）"

precheck() {
    log "预检"
    id www >/dev/null 2>&1 || die "无 www 用户（宝塔环境应存在）"
    [ -d /www/server/panel ] || warn "未检测到宝塔目录 /www/server/panel，继续以宝塔约定路径部署"
    # composer 依赖 proc_open：宝塔默认禁用，需提前解禁
    if "$PHP_BIN" -r 'exit(function_exists("proc_open")?0:1);'; then
        echo "  proc_open: OK"
    else
        die "PHP 禁用了 proc_open → 宝塔面板 PHP8.3 设置→禁用函数 删除 proc_open/proc_get_status/putenv/symlink/readlink"
    fi
    command -v git >/dev/null || die "缺少 git（宝塔软件商店或 dnf install git）"
}

prepare_dirs() {
    log "目录与代码"
    mkdir -p "${ROOT}"/{releases,shared/storage,shared/keys,bin,venv}
    if [ ! -d "${WEB}/artisan" ] && [ ! -f "${WEB}/artisan" ]; then
        rm -rf /tmp/ruoshu-src
        git clone --depth 1 "$REPO" /tmp/ruoshu-src
        mkdir -p "${ROOT}/current"
        cp -a /tmp/ruoshu-src/. "${ROOT}/current/"
    else
        echo "  代码已存在，跳过 clone"
    fi
    [ -f "${WEB}/artisan" ] || die "代码不完整：${WEB}/artisan 缺失"
}

composer_install() {
    log "Composer 依赖（--no-dev）"
    if ! command -v composer >/dev/null 2>&1; then
        ln -sf "$PHP_BIN" /usr/local/bin/php
        cd /tmp && "$PHP_BIN" -r 'copy("https://getcomposer.org/installer","composer-setup.php");'
        "$PHP_BIN" composer-setup.php --install-dir=/usr/local/bin --filename=composer
        rm -f composer-setup.php
    fi
    (cd "$WEB" && composer install --no-dev --optimize-autoloader --no-interaction)
}

gen_env() {
    log ".env"
    if [ -f "${ROOT}/shared/.env" ]; then
        echo "  已存在 shared/.env，保留"
    else
        cp "${WEB}/.env.example" "${ROOT}/shared/.env"
        "$PHP_BIN" -r '
            $f = "/www/wwwroot/ruoshu/shared/.env";
            $s = file_get_contents($f);
            $s = str_replace("DB_HOST=mysql", "DB_HOST=127.0.0.1", $s);
            $s = str_replace("REDIS_HOST=redis", "REDIS_HOST=127.0.0.1", $s);
            $s = str_replace("AI_PYTHON_BASE_URL=http://ai-python:9000", "AI_PYTHON_BASE_URL=http://127.0.0.1:9001", $s);
            $s = preg_replace("/^MEILISEARCH_HOST=.*$/m", "MEILISEARCH_HOST=", $s);
            file_put_contents($f, $s);
        '
        warn "请手工填写 ${ROOT}/shared/.env：DB_* / REDIS_PASSWORD / APP_URL / REALTIME_* / 支付与存证密钥"
    fi
    ln -sfn "${ROOT}/shared/.env" "${WEB}/.env"
}

gen_keys() {
    log "APP_KEY 与实时服务 RS256 密钥对"
    (cd "$WEB" && "$PHP_BIN" artisan key:generate --force)
    if [ ! -f "${ROOT}/shared/keys/realtime.pem" ]; then
        openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "${ROOT}/shared/keys/realtime.pem" 2>/dev/null
        openssl rsa -in "${ROOT}/shared/keys/realtime.pem" -pubout -out "${ROOT}/shared/keys/realtime.pub.pem" 2>/dev/null
        echo "  私钥：${ROOT}/shared/keys/realtime.pem  → 填 .env 的 REALTIME_JWT_PRIVATE_KEY（换行写 \\n）"
        echo "  公钥：${ROOT}/shared/keys/realtime.pub.pem → 填 Go 服务 JWT_PUBLIC_KEY"
    fi
}

link_storage() {
    log "storage / 权限（不走 artisan storage:link，symlink 通常被宝塔禁用）"
    cd "$WEB"
    rm -rf storage
    ln -sfn "${ROOT}/shared/storage" storage
    mkdir -p storage/app/public storage/framework/{cache/data,sessions,testing,views} storage/logs bootstrap/cache
    ln -sfn storage/app/public public/storage
    chown -R www:www "$ROOT"
    chmod -R ug+w storage bootstrap/cache
}

migrate_and_cache() {
    log "迁移 / 种子 / 缓存"
    cd "$WEB"
    "$PHP_BIN" artisan migrate --force
    "$PHP_BIN" artisan db:seed --class=GiftSeeder --force || warn "种子失败（可忽略，礼物表可后补）"
    "$PHP_BIN" artisan config:cache
    "$PHP_BIN" artisan route:cache
    "$PHP_BIN" artisan view:cache
}

nginx_hint() {
    log "Nginx 配置"
    SRC="${ROOT}/current/infra/deploy/bt/ruoshu-bt-locations.conf"
    if [ -f "$SRC" ]; then
        echo "  请把以下内容粘到：宝塔面板 → 网站 → 设置 → 配置文件 的 server{} 内"
        echo "  （保留宝塔生成的 listen / ssl_certificate）"
        echo "  文件：${SRC}"
        ls /www/server/panel/vhost/nginx/*.conf 2>/dev/null | head -5
    fi
}

sidecars() {
    if [ "${SKIP_AI:-0}" = "1" ]; then
        warn "跳过 Python AI 边车（SKIP_AI=1）→ 章节指纹任务将失败重试"
    else
        log "Python AI 边车"
        command -v python3 >/dev/null || { warn "无 python3，跳过（可稍后手工装）"; return 0; }
        [ -d "${ROOT}/venv/bin" ] || python3 -m venv "${ROOT}/venv"
        "${ROOT}/venv/bin/pip" install -q -r "${ROOT}/current/services/ai-python/requirements.txt"
    fi

    if [ "${SKIP_GO:-0}" = "1" ]; then
        warn "跳过 Go 构建（SKIP_GO=1）→ 需自行上传二进制到 ${ROOT}/bin/realtime"
    elif command -v go >/dev/null; then
        log "Go 实时服务"
        (cd "${ROOT}/current/apps/realtime" && CGO_ENABLED=0 go build -o "${ROOT}/bin/realtime" ./cmd/realtime)
    else
        warn "无 Go → 请在本机 CGO_ENABLED=0 GOOS=linux go build -o realtime ./cmd/realtime 后上传到 ${ROOT}/bin/realtime"
    fi
    chown -R www:www "${ROOT}" 2>/dev/null || true
}

systemd_units() {
    log "systemd 服务"
    cp "${ROOT}/current/infra/deploy/bt/"*.service /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable ruoshu-queue ruoshu-realtime ruoshu-ai-python >/dev/null 2>&1 || true
    systemctl restart ruoshu-queue ruoshu-realtime ruoshu-ai-python 2>/dev/null || warn "部分服务启动失败，见 systemctl status"
    echo "  调度器（仅一台机）：systemctl enable --now ruoshu-scheduler"
}

verify() {
    log "验收"
    systemctl --no-pager status ruoshu-queue --no-pager 2>/dev/null | head -3 || true
    echo "  curl -I https://你的域名/healthz   → 200"
    echo "  curl -s  https://你的域名/readyz   → {\"status\":\"ready\"}"
    echo "  curl http://127.0.0.1:8080/healthz → Go 实时"
    echo "  curl http://127.0.0.1:9001/docs    → AI 边车"
}

update() {
    log "更新：git pull + 依赖 + 迁移 + 缓存 + 重启队列"
    cd "${ROOT}/current" && git pull --ff-only
    (cd "$WEB" && composer install --no-dev --optimize-autoloader --no-interaction)
    cd "$WEB"
    "$PHP_BIN" artisan migrate --force
    "$PHP_BIN" artisan optimize:clear
    "$PHP_BIN" artisan config:cache && "$PHP_BIN" artisan route:cache && "$PHP_BIN" artisan view:cache
    systemctl restart ruoshu-queue ruoshu-realtime ruoshu-ai-python 2>/dev/null || true
    chown -R www:www "$ROOT"
    echo "  完成"
}

case "$MODE" in
    --init)
        precheck; prepare_dirs; composer_install; gen_env; gen_keys
        link_storage; migrate_and_cache; nginx_hint; sidecars; systemd_units; verify
        ;;
    --env-only)
        prepare_dirs; gen_env; gen_keys; link_storage
        echo -e "\n请填写 ${ROOT}/shared/.env 后执行：bash $0 --init"
        ;;
    --update)
        precheck; update
        ;;
    *) echo "用法: $0 [--init|--env-only|--update]"; exit 1 ;;
esac
