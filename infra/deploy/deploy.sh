#!/usr/bin/env bash
# 若书 · 生产部署脚本（任务 13.5）
#
# 目录约定（rsync 发布，非 git 依赖）：
#   /srv/ruoshu/releases/<ts>/{apps,infra,services}/   每次发布独立目录（仓库结构原样）
#   /srv/ruoshu/current -> releases/<ts>                原子软链切换（web 在 current/web）
#   /srv/ruoshu/shared/.env + shared/storage            跨版本共享（apps/web/.env、apps/web/storage 软链过去）
#
# 用法（发布机执行，双机先 11 后 12）：
#   bash infra/deploy/deploy.sh deploy@10.0.0.11
#   bash infra/deploy/deploy.sh deploy@10.0.0.12
# 任一台 healthz 探活失败 → 回滚该台并中止后续。
set -euo pipefail

TARGET="${1:?用法: deploy.sh <user@host>}"
APP_DIR="/srv/ruoshu"
RELEASE="${2:-$(date +%Y%m%d%H%M%S)}"
SSH="ssh -o BatchMode=yes -o ConnectTimeout=10 ${TARGET}"
REL="${APP_DIR}/releases/${RELEASE}"

# ===== 0. 本地构建（发布机需要 php/go/node 工具链）=====
[ -f apps/web/artisan ] || { echo "!! 请在仓库根目录执行"; exit 1; }
if [ ! -d apps/web/vendor ] || [ -f apps/web/vendor/.deploy-stale ]; then
    echo "!! 请先执行: (cd apps/web && composer install --no-dev --optimize-autoloader)"; exit 1
fi
command -v go >/dev/null && (cd apps/realtime && CGO_ENABLED=0 GOOS=linux go build -o /tmp/ruoshu-realtime ./cmd/... 2>/dev/null \
    || CGO_ENABLED=0 GOOS=linux go build -o /tmp/ruoshu-realtime .) || { echo "!! 请先构建: (cd apps/realtime && go build)"; exit 1; }

echo "==> [1/6] 同步代码 ${TARGET} -> releases/${RELEASE}"
$SSH "mkdir -p ${REL} ${APP_DIR}/shared/storage"
rsync -az --delete \
    --exclude '.git' --exclude 'node_modules' --exclude '.env*' \
    --exclude 'apps/web/storage/framework' --exclude 'apps/web/storage/logs' \
    --exclude '*.log' \
    apps/web/ "${TARGET}:${REL}/apps/web/"
rsync -az services/ "${TARGET}:${REL}/services/"
rsync -az infra/ "${TARGET}:${REL}/infra/"
rsync -az /tmp/ruoshu-realtime "${TARGET}:${REL}/bin/realtime"

echo "==> [2/6] 布置共享目录（.env / storage 跨版本，软链原子）"
$SSH "cd ${REL}/apps/web \
    && ln -sfn ${APP_DIR}/shared/.env .env \
    && rm -rf storage \
    && ln -sfn ${APP_DIR}/shared/storage storage \
    && mkdir -p bootstrap/cache storage/framework/{cache/data,sessions,testing,views} storage/logs \
    && chmod -R ug+w bootstrap/cache storage"

echo "==> [3/6] 缓存配置/路由/视图（current 切换前在 release 目录完成，切换即生效）"
$SSH "cd ${REL}/apps/web \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache"

echo "==> [4/6] 原子切换 current（滚动窗口内双机可异版共存：读写库共享，schema 兼容）"
$SSH "ln -sfn ${REL} ${APP_DIR}/current_test && mv -T ${APP_DIR}/current_test ${APP_DIR}/current"

echo "==> [5/6] 迁移（flock 防双机并发）"
$SSH "cd ${APP_DIR}/current/apps/web \
    && flock -w 120 /tmp/ruoshu-migrate.lock php artisan migrate --force"

echo "==> [6/6] 重启服务 + 探活"
$SSH "systemctl restart ruoshu-queue ruoshu-realtime ruoshu-ai-python 2>/dev/null || \
      systemctl restart php8.3-fpm 2>/dev/null || true"

sleep 3
if ! $SSH "curl -fsS -m 5 http://127.0.0.1/healthz >/dev/null"; then
    echo "!! 探活失败 -> 回滚 ${TARGET} 到上一版本"
    $SSH "PREV=\$(ls -1t ${APP_DIR}/releases | sed -n '2p') \
        && ln -sfn ${APP_DIR}/releases/\${PREV} ${APP_DIR}/current_test \
        && mv -T ${APP_DIR}/current_test ${APP_DIR}/current \
        && systemctl restart ruoshu-queue ruoshu-realtime 2>/dev/null || true"
    exit 1
fi
$SSH "curl -fsS -m 5 http://127.0.0.1/readyz >/dev/null" \
    || echo "WARN: readyz 失败（DB/Redis 层），数据问题回滚无效，请立即人工介入"

echo "==> 完成：${RELEASE} @ ${TARGET}（回滚：bash infra/deploy/rollback.sh ${TARGET}）"
