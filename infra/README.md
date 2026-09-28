# infra — 基础设施与部署

## 本地开发（docker-compose）

```bash
# 在 infra/ 目录下：
docker compose up -d

# 等待服务就绪（约 30 秒）
docker compose ps

# 验证
curl http://localhost:8000           # Laravel
curl http://localhost:8080/healthz   # Go realtime
curl http://localhost:7700/health     # Meilisearch
```

数据库、Redis、Meilisearch 数据持久化到 named volumes，重启容器不丢数据。
清空全部数据：`docker compose down -v`

## 生产部署（双机起步）

```
节点1 (CVM 2C4G, ¥200/月)
├─ nginx (反向代理 + HTTPS 终止)
├─ apps/web (PHP-FPM + Laravel, port 9000 内网)
├─ apps/realtime (Go, port 8080 内网)
└─ Horizon 队列消费

节点2 (云数据库/Redis)
├─ MySQL 8.4 (4C8G, ¥500/月)
└─ Redis 7.4 (1G, ¥50/月)

外部
├─ OSS（封面/静态/导出包）
├─ CDN（静态加速）
└─ 至信链 / TSA / 微信支付 / 支付宝
```

总成本约 ¥750-1000/月（含云数据库/Redis），不含外部 API 调用费。

## 云资源开通清单

| 资源 | 规格 | 数量 | 月费 | 备注 |
|------|------|------|------|------|
| CVM 应用 | 标准 SA5.2C4G | 1 | ~200 | nginx + web + realtime 共部署 |
| 云数据库 MySQL 8.4 | 基础版 2C4G/100G SSD | 1 | ~500 | 启用自动备份（默认 7 天） |
| Redis | 标准 1G | 1 | ~50 | |
| OSS | 标准存储 + CDN 回源 | - | ~100 | 起步量小 |
| CDN | 国内流量包 | - | ~50 | 起步 |
| 至信链 | 1 元/条 | - | ~2000 | 月存证 2000 条预估 |
| TSA | 按需 | - | ~50 | 月度对账单 + 取证包 |
| 短信 | 国内通用包 | - | ~50 | 验证码 / 通知 |
| 邮件 | 阿里云邮推送 | - | ~10 | |
| **合计** | | | **~3010** | 不含外部支付通道费率 |

加域名带宽后实际响应负载：约 ¥6000-8000/月（含支付通道 1-3%）。

## 生产部署脚本

`provision.sh`（任务 1.7）会创建：
- CVM 系统盘 + 数据盘 + 安全组规则
- 云数据库实例 + 数据库账号 + 跨域授权
- Redis 实例 + 密码
- OSS Bucket + CORS + 防盗链
- CDN 加速域名 + HTTPS 证书
- 至信链 / TSA 账号申请单（需人工）

详见 `provision.sh`（独立脚本文件）。

## 监控告警建议

- 接口错误率 > 1% → 短信告警
- 队列积压 > 1000 → 钉钉告警
- 至信链死信 > 10 → 钉钉告警
- 节点 CPU > 70% 持续 5 分钟 → 短信告警

## 生产部署执行手册（任务 13.5，`infra/deploy/`）

### 目录约定

```
/srv/ruoshu/releases/<ts>/   每次发布独立目录（仓库结构原样：apps/ services/ infra/ bin/）
/srv/ruoshu/current -> releases/<ts>   原子软链（Web 根 = current/apps/web/public）
/srv/ruoshu/shared/.env|storage/       跨版本共享（.env 与 storage 软链入 release）
```

### 首次初始化（每台机执行一次）

```bash
mkdir -p /srv/ruoshu/{releases,shared/storage}
cp <生产.env> /srv/ruoshu/shared/.env
cp infra/deploy/systemd/*.service /etc/systemd/system/
systemctl daemon-reload && systemctl enable ruoshu-queue ruoshu-realtime ruoshu-ai-python
# 调度器仅 1 号机：systemctl enable ruoshu-scheduler
cp infra/deploy/nginx/ruoshu-php.conf /etc/nginx/snippets/
ln -s /srv/ruoshu/infra/deploy/nginx/ruoshu.conf /etc/nginx/sites-enabled/
```

### 发布与回滚

```bash
cd apps/web && composer install --no-dev --optimize-autoloader && cd ../..
bash infra/deploy/deploy.sh deploy@10.0.0.11   # 双机依次，任一台探活失败自动回滚该台
bash infra/deploy/deploy.sh deploy@10.0.0.12
bash infra/deploy/rollback.sh deploy@10.0.0.11 [版本]   # 秒级回滚
```

### 监控（cron，双机各一份）

```bash
# crontab -e
* * * * * OPS_WEBHOOK=<企业微信机器人URL> OPS_PEER=<对端IP> \
    /srv/ruoshu/current/infra/deploy/monitor.sh >> /var/log/ruoshu-monitor.log 2>&1
```

指标：healthz/readyz 双机探测、队列积压 >1000、存证死信、nginx 5 分钟窗口 5xx >1%（同键 30 分钟静默）。应用层另有 `QueueHealthCheckJob`（每 5 分钟）自检兜底。

### 安全（13.7 网关侧）

- 限流：`ruoshu.conf` 内 auth 10r/m / api 30r/s / report 5r/m（应用层 throttle 第二道）
- 后台：`admin.conf` 独立子域 = IP 白名单 + Basic Auth + 应用层 `can:admin` & `EnsureAdminAccess` 三闸
- 支付回调：仅微信/支付宝出口 IP + 回调验签（幂等重放安全）