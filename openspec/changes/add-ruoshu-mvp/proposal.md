# Proposal: add-ruoshu-mvp

## Why

国内小说平台普遍要求版权买断/独家授权且抽成高达 30-50%，作者失去作品控制权与收益透明度；
面向女频/轻小说创作者的"作者友好型"平台是真实市场空白。本项目（若书 ruoshu）以
「作品归作者 + 技术性版权保护」为核心叙事，需要从零搭建可上线的 MVP，验证
"版权保护能力可以成为作者选择平台的决定性因素"这一假设。前期研究与设计基线已完成
（见 `ruoshu/docs/research/` 与 `ruoshu/docs/design/`，8 项关键决策已锁定）。

## What Changes

- **新建** 全平台代码库：Laravel 主站 + Go 实时服务 + Python AI 边车（方案 B 三件套）
- **新增** 用户与角色体系：读者/作者/管理员，微信扫码注册，作者实名
- **新增** 作者工作台：作品/章节管理、富文本编辑器、发布流程（发布即异步存证）
- **新增** 阅读体验：作品详情页、章节阅读页（三主题/移动端优先）、书架与进度
- **新增** 内容发现：6 大类 + 标签云、Meilisearch 站内搜索、首页信息流
- **新增** 付费体系：礼物打赏（主）、章节单购、月卡订阅；ledger 唯一真源分账（15%/5%/10% 抽成）
- **新增** 版权存证：至信链逐章确权 + 段落 Merkle 指纹 + 存证徽章/验证页
- **新增** 盲水印（一期）：付费章节零宽字符 per-user 水印 + 溯源工作台
- **新增** 作者主权：CC 授权选择器、Markdown/TXT 一键导出、RSS 输出
- **新增** 互动阅读：段落评论 + 实时弹幕（Go WebSocket，降级轮询）
- **新增** 管理后台：内容审核、举报处理、财务对账（独立子域 + 2FA）

MVP 明确**不做**：独家签约、海外出海支付、字体混淆反爬（W4）、同义词水印（W2）、
ActivityPub 联邦、推荐算法（用编辑人工 + 标签匹配代替）。

## Capabilities

### New Capabilities

- `user-auth`: 注册登录（微信扫码 + 账号密码）、角色体系（读者/作者/管理员）、作者实名认证
- `author-workspace`: 作品与章节管理、富文本编辑器（自动保存）、发布流水线（发布触发存证/水印/RSS 异步任务）
- `reading-experience`: 作品详情页、章节阅读页（三主题/字号可调/移动端优先）、书架、阅读进度
- `content-discovery`: 6 大类 + 标签云浏览、Meilisearch 搜索（书名/作者/标签）、首页信息流模块
- `payment-ledger`: 礼物打赏、章节单购、月卡订阅三种收入；ledger 唯一真源账本；微信/支付宝官方分账；作者实时收益看板
- `copyright-evidence`: 章节发布异步至信链存证、段落 SHA-256 指纹（Merkle root）、存证徽章与公开验证页
- `blind-watermark`: 付费章节 per-user 零宽字符水印（W1）、水印种子管理、盗版溯源工作台（提交盗版文本 → 指纹比对 → 定位泄露账号）
- `author-sovereignty`: CC 授权选择器（5 档）、作品数据一键导出（Markdown/TXT）、RSS/Atom 输出
- `interactive-reading`: 段落评论（多态锚点）、实时弹幕（Go WebSocket，断线降级 5s 轮询）
- `admin-moderation`: 作品/评论审核队列、举报处理、财务对账、操作审计留痕

### Modified Capabilities

（无 —— 全新项目，无既有规格）

## Impact

- **代码库**：`ruoshu/` 下新建 monorepo：`apps/web`(Laravel)、`apps/realtime`(Go)、`services/ai-python`(边车)、`infra/`(部署)
- **外部依赖**：腾讯云（CVM/云数据库/OSS/CDN）、至信链 API、TSA API、微信开放平台+支付、支付宝支付、Meilisearch
- **数据**：MySQL 8（utf8mb4，正文垂直拆分）、Redis（热章缓存/弹幕/会话）、Meilisearch 索引
- **成本基线**：月运营 6000-8000 元（服务器 ~1000 + 云数据库 ~500 + 存证 ~2000 + CDN/杂项 ~1000-3000）
- **合规**：作者协议与 CC 协议文本需法务审阅后方可上线；管理后台动作全程审计
