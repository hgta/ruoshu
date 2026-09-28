# 若书（ruoshu）小说平台

> 原创小说平台 · 版权保护 · 作者友好 · 技术选型：Laravel + Go + Python

## 项目结构

```
ruoshu/
├── apps/
│   ├── web/          Laravel 11 主站（业务 + API + 队列）
│   └── realtime/     Go 1.22+ 实时服务（WebSocket：段评/弹幕/通知）
├── services/
│   └── ai-python/    FastAPI 文本处理边车（水印/指纹/规范化）
├── infra/            docker-compose（开发） / 部署脚本 / nginx
├── docs/             研究与设计基线（research + design 目录）
├── openspec/         OpenSpec 变更管理（change + archive + specs）
└── .github/workflows CI 配置
```

## 服务边界

| 服务 | 负责 | 不负责 |
|------|------|--------|
| `apps/web` (Laravel) | 业务页面、API、作品/章节、支付分账、存证调度、admin | 长连接广播、文本处理 NLP |
| `apps/realtime` (Go) | WebSocket 鉴权、订阅 Redis 频道广播、连接管理 | 业务数据、持久化 |
| `services/ai-python` (FastAPI) | 文本规范化、段落指纹、Merkle root、零宽水印方案规划/提取 | 任何 HTTP/WebSocket 业务 |

## 通信

- **Laravel → Go**：Redis Pub/Sub（`book:{id}:ch:{id}` 广播弹幕/段评）
- **Laravel → Python**：内网 HTTP（同步，超时 3s）
- **队列**：Laravel Horizon（Redis 队列）处理存证上链、水印种子生成、导出打包

## 快速启动（开发环境）

```bash
# 1. 启动基础数据服务（MySQL/Redis/Meilisearch）+ 全部应用服务
cd infra && docker compose up -d

# 2. 初始化 Laravel 数据库
docker compose exec web php artisan migrate --seed

# 3. 浏览器访问
#    http://localhost:8000          读者端
#    http://localhost:8000/admin    管理后台（admin 子域）
#    ws://localhost:8080            WebSocket 实时层
#    http://localhost:9000/docs     Python 边车 API 文档
```

详细见 `infra/README.md`。

## OpenSpec 变更

```
openspec/
├── changes/         进行中的变更提案（含 delta specs）
│   └── add-ruoshu-mvp/   当前 MVP 工单
└── specs/           主规格目录（archive 时合并）
```

工单状态：`openspec status --change add-ruoshu-mvp`

## 关键文档

- `docs/research/`  背景研究（5 份）+ 决策记录（8 项已锁）
- `docs/design/`    设计基线（信息架构 / 用户旅程 / ER 图）
- `openspec/changes/add-ruoshu-mvp/` 工单（proposal + design + 10 规格 + 73 任务）