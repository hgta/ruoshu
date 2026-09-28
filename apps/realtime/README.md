# apps/realtime — Go 实时服务

**负责**：WebSocket 鉴权握手、订阅 Redis 频道转发、连接管理、健康检查
**不负责**：任何业务数据、数据库访问、文本处理

## 启动（本地）

```bash
go mod download
go run ./cmd/realtime
# 默认 :8080，可通过 REALTIME_PORT 调整
```

## 配置（环境变量）

| 变量 | 必填 | 说明 |
|------|------|------|
| `REALTIME_PORT` | 否 | 监听端口，默认 8080 |
| `REDIS_ADDR` | 是 | Redis 地址，如 `redis:6379` |
| `JWT_PUBLIC_KEY` | 是 | Laravel 签发 JWT 的 RSA 公钥路径，用于鉴权握手 |
| `JWT_ISSUER` | 是 | JWT iss 校验值 |
| `LOG_LEVEL` | 否 | 默认 `info` |

## 接口

- `GET /healthz`           健康检查
- `GET /readyz`            就绪检查（依赖 Redis 可用）
- `GET /ws`                WebSocket 端点（带 JWT token 鉴权）

## Redis 频道约定

Laravel 侧按以下频道发布消息，Go 自动转发给已订阅的客户端：

| 频道 | 载荷 | 触发 |
|------|------|------|
| `book:{book_id}:ch:{chapter_id}:comment` | 段评/章评推送 | 新评论 |
| `book:{book_id}:ch:{chapter_id}:danmu` | 弹幕 | 新弹幕 |
| `book:{book_id}:ch:{chapter_id}:donation` | 打赏回礼 | 打赏成功 |
| `author:{author_id}:notify` | 作者侧通知 | 打赏 / 新订阅 / 互动 |

## 鉴权

客户端连接 URL：`/ws?token=<JWT>`。JWT 由 Laravel 颁发（短时效，5 分钟），包含 `user_id` 与 `role`。
Go 仅做签名校验与会话建立，不查 DB。

## 优雅降级

WebSocket 不可用时，前端自动切 5 秒轮询（HTTP 端点由 Laravel 提供）。
Go 服务重启不影响主站可用性。

## 文档

- 互动阅读能力规格：`openspec/changes/add-ruoshu-mvp/specs/interactive-reading/spec.md`