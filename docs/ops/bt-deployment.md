# 若书 · 宝塔面板首次部署手册（CentOS 8 / 宝塔 11.8.1 / Nginx 1.16.1 / PHP 8.3）

> 适用环境：CentOS 8 + 宝塔 Linux 面板 v11.8.1 + Nginx 1.16.1 + PHP 8.3.33 + MySQL 5.7.28
> 配套脚本：`infra/deploy/bt/bootstrap-bt.sh`（第 2~9 步一键化）
> 配套配置：`infra/deploy/bt/`（nginx 片段 + systemd 单元）
> 通用（非宝塔）部署：`infra/README.md`

---

## 0. 环境前提与硬性约束

| 项 | 要求 | 现状 | 结论 |
|---|---|---|---|
| PHP | ^8.3（Laravel 12） | 8.3.33 | 满足 |
| 扩展 | pdo_mysql / bcmath / gd / intl / zip / mbstring / curl / redis / fileinfo | 需确认 | 宝塔 PHP 设置里勾选 |
| MySQL | **官方要求 8.0+** | 5.7.28 | 可用但不受支持，建议升 8.0（见 §10） |
| Redis | **必需**（队列/缓存/会话/广播全部走 Redis） | 未装 | 必须装 |
| Nginx | 任意 | 1.16.1 | 满足，需改 `fastcgi_pass` 为 unix socket |
| 禁用函数 | `proc_open`/`putenv`/`symlink` 必须解禁 | 宝塔默认禁用 | 必须改 |

可选服务（不装也能跑）：
- **Meilisearch**：不装则 `SearchService` 自动降级 MySQL LIKE 搜索
- **Python AI 边车**：不装则 `FingerprintChapterJob` 失败重试，章节指纹缺失 → **建议装**

---

## 1. 宝塔面板 UI 操作

### 1.1 软件商店
- **Redis** → 安装（6379，设密码）
- **PHP 8.3** → 设置 → 安装扩展：`redis` `bcmath` `intl` `zip` `gd` `opcache` `fileinfo` `exif` `pcntl`
- **MySQL 8.0**（推荐，端口改 3307 与 5.7 并存；搜不到见 §10）
- **Python 项目管理器** 或确保有 `python3.9+`

### 1.2 PHP 8.3 设置 → 禁用函数
删除（其余保留）：
```
proc_open, proc_get_status, putenv, symlink, readlink
```
> 不解禁：composer 报 `The Process class relies on proc_open`；`storage:link` 建不出来（本手册改用 shell `ln -s`）。

### 1.3 PHP 8.3 设置 → 配置
```
memory_limit = 256M
upload_max_filesize = 8M
post_max_size = 10M
max_execution_time = 120
```
确认 opcache 开启。确认 socket：`ls /tmp/php-cgi-*.sock` → `/tmp/php-cgi-83.sock`

### 1.4 建站点
- 域名 `ruoshu.example.com`，根目录 `/www/wwwroot/ruoshu/current/web/public`，PHP 版本 **8.3**
- SSL → Let's Encrypt 申请
- **关闭「防跨站攻击(open_basedir)」**

### 1.5 安全 → 放行端口
放行 `80/443`；`8080`(WS) / `9001`(AI) **仅内网监听，不放公网**

---

## 2. 命令行：基础工具

```bash
ln -s /www/server/php/83/bin/php /usr/local/bin/php
php -v

# CentOS 8 已 EOL，若 yum/dnf 报 repo 错，先切 vault（仅一次）
# sed -i 's|^mirrorlist|#mirrorlist|; s|^#baseurl=http://mirror.centos.org|baseurl=https://vault.centos.org|' /etc/yum.repos.d/CentOS-*
# dnf clean all && dnf makecache

cd /tmp && php -r "copy('https://getcomposer.org/installer','composer-setup.php');"
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm -f composer-setup.php && composer -V
```

---

## 3. 拉代码 + 装依赖

```bash
mkdir -p /www/wwwroot/ruoshu/{releases,shared/storage,bin,venv}
git clone git@github.com:hgta/ruoshu.git /tmp/ruoshu-src
mkdir -p /www/wwwroot/ruoshu/current && cp -a /tmp/ruoshu-src/. /www/wwwroot/ruoshu/current/

cd /www/wwwroot/ruoshu/current/web
composer install --no-dev --optimize-autoloader
```

---

## 4. `.env`（最关键）

```bash
cp .env.example /www/wwwroot/ruoshu/shared/.env
ln -sfn /www/wwwroot/ruoshu/shared/.env /www/wwwroot/ruoshu/current/web/.env
vi /www/wwwroot/ruoshu/shared/.env
```

必填：
```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ruoshu.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3307              # MySQL 8.0；仍用 5.7 则 3306
DB_DATABASE=ruoshu
DB_USERNAME=ruoshu
DB_PASSWORD=强密码

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=你的redis密码
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=redis

# 实时服务：Laravel 与 Go 两侧 issuer 必须一致
REALTIME_WS_URL=wss://ruoshu.example.com/ws
REALTIME_JWT_ISSUER=ruoshu-realtime
REALTIME_JWT_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----\n"

AI_PYTHON_BASE_URL=http://127.0.0.1:9001
# MEILISEARCH_HOST 留空 → 自动降级 LIKE 搜索
FEATURES_PAYMENT=false          # 支付未就绪前保持 false
FEATURES_REGISTRATION=true
```

生成 APP_KEY 与 JWT 密钥对：
```bash
php artisan key:generate --force
mkdir -p /www/wwwroot/ruoshu/shared/keys
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out /www/wwwroot/ruoshu/shared/keys/realtime.pem
openssl rsa -in /www/wwwroot/ruoshu/shared/keys/realtime.pem -pubout -out /www/wwwroot/ruoshu/shared/keys/realtime.pub.pem
```

---

## 5. storage 与权限（不要跑 `artisan storage:link`）

```bash
cd /www/wwwroot/ruoshu/current/web
rm -rf storage && ln -sfn /www/wwwroot/ruoshu/shared/storage storage
mkdir -p storage/{app/public,framework/{cache/data,sessions,testing,views},logs} bootstrap/cache
chown -R www:www /www/wwwroot/ruoshu
chmod -R ug+w storage bootstrap/cache
ln -sfn storage/app/public public/storage
```

---

## 6. 迁移 + 缓存 + 种子

```bash
php artisan migrate --force
php artisan db:seed --class=GiftSeeder --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

---

## 7. Nginx：套用宝塔片段

把 `infra/deploy/bt/ruoshu-bt-locations.conf` 内容粘到
面板 → 网站 → 设置 → **配置文件** 的 `server{...}` 内（替换原 location 段，**保留宝塔的 `listen`/`ssl_certificate`**）。

要点：
```nginx
fastcgi_pass unix:/tmp/php-cgi-83.sock;     # 宝塔必须走 socket，不是 127.0.0.1:9000

location /ws {                               # WebSocket
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 3600s;
}
```
改完 `nginx -t` 通过 → 重载。

---

## 8. 边车服务

```bash
# Python AI 边车（入口模块 app.main:app，端口 9001）
python3 -m venv /www/wwwroot/ruoshu/venv
/www/wwwroot/ruoshu/venv/bin/pip install -r /www/wwwroot/ruoshu/current/services/ai-python/requirements.txt

# Go 实时服务（服务器装 Go，或本机 GOOS=linux 交叉编译后上传到 /www/wwwroot/ruoshu/bin/realtime）
cd /www/wwwroot/ruoshu/current/apps/realtime
CGO_ENABLED=0 go build -o /www/wwwroot/ruoshu/bin/realtime ./cmd/realtime
```

---

## 9. systemd 服务

```bash
cp /www/wwwroot/ruoshu/current/infra/deploy/bt/*.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now ruoshu-queue ruoshu-realtime ruoshu-ai-python ruoshu-scheduler
systemctl status ruoshu-queue ruoshu-realtime ruoshu-ai-python
```
> `ruoshu-scheduler` **只开一台**（重复开会跑双份每日存证 / 弹幕归档 / 健康检查）。

监控（宝塔 → 计划任务 → Shell，每分钟）：
```bash
OPS_WEBHOOK=<机器人URL> /www/wwwroot/ruoshu/current/infra/deploy/monitor.sh >> /var/log/ruoshu-monitor.log 2>&1
```

---

## 10. MySQL 8.0 在宝塔搜不到怎么办

**常见原因**
1. 软件商店里 **MySQL 是一个条目，版本在点「安装」后的下拉框里选**（5.5/5.6/5.7/8.0），不是搜 "mysql8.0"
2. **CentOS 8 已 EOL**：宝塔 11.x 对 CentOS 8 的部分安装源已下线，MySQL 8.0 包可能被隐藏
3. 已装 5.7 时，商店显示的是「已安装/切换版本」入口
4. 内存不足（MySQL 8.0 建议 ≥2G，编译安装需更多）时，宝塔会隐藏或安装失败

**替代方案（按推荐度）**
1. 宝塔 **Docker 管理器** 跑 `mysql:8.0` 容器（端口映射 3307:3306，数据卷挂 `/www/wwwroot/mysql8-data`）
2. 官方源安装：
   ```bash
   dnf install -y https://repo.mysql.com/mysql80-community-release-el8-1.noarch.rpm
   dnf --enablerepo=mysql80-community install -y mysql-community-server
   ```
   （CentOS 8 EOL 时先按 §2 切 vault 源）
3. **继续用 5.7**：本项目未使用 MySQL 8 专属特性（无 CTE/窗口函数/fullText/生成列），框架默认 collation 为 `utf8mb4_unicode_ci`（5.7 支持）。先跑 `php artisan migrate --force` 验证，出问题看 `storage/logs/laravel.log`。
4. 长期建议迁到 **Rocky Linux 8 / AlmaLinux 8**（宝塔支持、yum 源有效），CentOS 8 已无安全更新。

---

## 11. 验收

```bash
curl -I https://ruoshu.example.com/healthz     # 200
curl -s  https://ruoshu.example.com/readyz     # {"status":"ready","checks":{"db":"ok","redis":"ok"}}
curl http://127.0.0.1:8080/healthz             # Go 实时
curl http://127.0.0.1:9001/docs                # AI 边车
systemctl status ruoshu-queue                  # active (running)
```
浏览器：首页 → 注册 → 发一章 → 作者工作台；DevTools 看 `/ws` 是否 101 升级。

---

## 12. 排障速查

| 症状 | 原因 / 处理 |
|---|---|
| composer 报 `relies on proc_open` | PHP 禁用函数未删 `proc_open`/`putenv` |
| 502 Bad Gateway | `fastcgi_pass` 还写着 `127.0.0.1:9000`，宝塔应为 `unix:/tmp/php-cgi-83.sock` |
| 500 且无日志 | `storage`/`bootstrap/cache` 权限，需 `chown -R www:www` |
| 502 + `Permission denied` | SELinux：`setenforce 0` 并改 `/etc/selinux/config` 为 `disabled` |
| WS 连不上 | `REALTIME_JWT_ISSUER`(Laravel) == `JWT_ISSUER`(Go)，且公私钥是同一对 |
| 改代码不生效 | `php artisan optimize:clear && php artisan config:cache route:cache view:cache`，并 `systemctl restart ruoshu-queue`（队列内旧代码不会自动更新） |
| 搜索无结果 | 未装 Meilisearch → 已降级 LIKE；或 `SyncBookToSearchIndexJob` 未跑 |
| 章节存不到指纹 | AI 边车未起（9001），`FingerprintChapterJob` 会重试 5 次后失败 |

---

## 13. 后续发布（首次装完后）

```bash
# 发布机（本地）
cd apps/web && composer install --no-dev --optimize-autoloader && cd ../..
cd apps/realtime && CGO_ENABLED=0 GOOS=linux go build -o /tmp/ruoshu-realtime ./cmd/realtime

bash infra/deploy/deploy.sh root@<服务器IP>     # 原子软链切换 + 探活失败自动回滚
bash infra/deploy/rollback.sh root@<服务器IP>   # 秒级回滚
```

> 若直接沿用本手册的 `current` 单目录（未用 releases），后续更新用：
> `git pull && composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan optimize:clear && php artisan config:cache route:cache view:cache && systemctl restart ruoshu-queue`
