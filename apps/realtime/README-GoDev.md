# apps/realtime — Go 实时服务开发说明

## 编译

```bash
cd apps/realtime
go mod download
go build -o bin/realtime ./cmd/realtime
./bin/realtime
```

## 必需环境变量（开发）

```bash
export REDIS_ADDR=localhost:6379
export JWT_PUBLIC_KEY="$(cat /path/to/dev_public.pem)"
export JWT_ISSUER=ruoshu-dev
export REALTIME_PORT=8080
```

## 单元测试

```bash
go test ./...
```

## 代码结构

```
apps/realtime/
├── go.mod
├── README.md           # 用户视角
├── README-GoDev.md     # 本文件
└── cmd/realtime/
│   └── main.go
└── internal/
    ├── auth/verifier.go       # JWT 验签（仅握手）
    ├── config/config.go       # env 加载
    ├── hub/hub.go             # 连接池 + 广播循环
    └── ws/handler.go          # WebSocket 升级 + 读写循环
```

## Redis 频道约定（与 Laravel 侧约定一致）

- `book:{book_id}:ch:{chapter_id}:comment`  — 段评/章评推送
- `book:{book_id}:ch:{chapter_id}:danmu`    — 弹幕
- `book:{book_id}:ch:{chapter_id}:donation` — 打赏回礼
- `author:{author_id}:notify`               — 作者侧通知

载荷 JSON 格式：`{"type": "comment|danmu|donation|notify", "payload": {...}}`

## JWT payload 格式（Laravel 侧颁发）

```json
{
  "iss": "ruoshu",
  "sub": "<user_uuid>",
  "uid": 8848,
  "role": "reader",
  "exp": 1735689600
}
```

TTL 默认 300 秒；客户端拿到后立刻连接，握手失败自动走轮询降级。

## 注意事项

- JWT 公钥每 90 天滚动；Hub 启动时一次性加载，**不支持热重载**（后续可加 reload channel）
- 单节点 MVP；多节点水平扩容时改用 Redis Stream 而非 Pub/Sub（保证跨节点消息顺序）
- 当前所有消息全节点广播（按 chapter 订阅未实现）；M2 再细化按 chapter 路由