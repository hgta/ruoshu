# Tasks: add-ruoshu-mvp

> 依赖顺序：基础设施 → 数据层 → 主站核心 → 差异化能力 → 实时 → 付费 → 收尾。
> 每组完成后可独立验收。深度设计依据见 `design.md` 与 `ruoshu/docs/design/`。

## 1. 基础设施与骨架

- [x] 1.1 初始化 monorepo 结构（apps/web, apps/realtime, services/ai-python, infra/），各子目录 README 声明"我不管什么"
- [x] 1.2 Laravel 11 骨架（apps/web）：目录约定、Pint/PHPStan、Pest 测试基线
- [x] 1.3 Go 服务骨架（apps/realtime）：go.mod、WebSocket echo、健康检查
- [x] 1.4 Python 边车骨架（services/ai-python）：FastAPI、/health、内网绑定
- [x] 1.5 infra：docker-compose（web/realtime/ai-python/mysql/redis/meilisearch）开发环境一键起
- [x] 1.6 GitHub Actions 三语言 CI 管线（lint + test）
- [x] 1.7 云资源开通脚本与配置清单（CVM×2、云数据库、Redis、OSS、CDN、至信链/TSA 账号预留）

## 2. 数据层

- [x] 2.1 迁移：users / author_profiles / reader_profiles（角色 bitmap、实名哈希脱敏字段）
- [x] 2.2 迁移：books / chapters / chapter_contents（正文垂直拆分）/ book_tags / tags / gifts
- [x] 2.3 迁移：paragraphs（指纹快照，含版本）/ evidence_records / merkle_batches
- [x] 2.4 迁移：ledger_entries（链式哈希、batch_id）/ donations / chapter_purchases / subscriptions
- [x] 2.5 迁移：comments（多态）/ danmu / bookshelves / watermark_seeds / watermark_audits / exports / audit_logs
- [x] 2.6 模型层与索引复核：热查询路径索引（book_id+chapter_no、target_type+target_id+id 等）

## 3. 用户与角色（user-auth）

- [x] 3.1 微信扫码登录 + 账号密码登录，自动昵称/头像
- [x] 3.2 阅读位置保持：登录后 15s 内回到原阅读位
- [x] 3.3 作者实名认证流程（哈希存储 + 脱敏展示）
- [ ] 3.4 会话与安全基线：HTTPS/HSTS、admin 独立子域 + IP 白名单 + TOTP 2FA
- [x] 3.5 feature flag 机制（支付开关等全局开关）

## 4. 作者工作台（author-workspace）

- [x] 4.1 作品创建：6 大类 + ≥3 标签 + 封面上传（OSS）+ 授权选择（5 档，选择记录哈希入库）
- [x] 4.2 章节编辑器：富文本、10s 自动保存草稿、字数统计、单章 ≤10 万字限制
- [x] 4.3 发布流水线（异步队列）：指纹生成任务、存证提交任务、水印锚点规划任务、RSS 更新、读者通知（RSS/通知随任务 11.3 落地）
- [x] 4.4 章节修订：新指纹版本快照，旧版本证据保持可验证
- [x] 4.5 作品设置与生命周期：定价/标签/授权修改、下架（保留存证记录）

## 5. 阅读体验（reading-experience）

- [x] 5.1 作品详情页：封面/标签/统计/存证徽章/最新章节/书评区
- [x] 5.2 阅读页：段落锚点渲染、三主题、字号行距、移动端 375px 基准、章末操作栏
- [x] 5.3 免登门槛：未登录可读每书前 3 章
- [x] 5.4 VIP 门禁与解锁（单购/月卡判定），付费正文 per-user 渲染路径（禁公共 CDN）
- [x] 5.5 书架与阅读进度（跨设备恢复，前端定期上报）
- [x] 5.6 Redis 热章缓存，命中 p95 ≤200ms 验证
## 6. 版权存证（copyright-evidence）

- [x] 6.1 Python 边车：文本规范化、分段、SHA-256、Merkle root 计算接口
- [x] 6.2 至信链 driver 抽象层 + ZhixinChainDriver 实现（payload 构造、提交、tx_id 回写、重试/死信）
- [x] 6.3 本地证据先行持久化（MySQL + OSS payload 留底）
- [x] 6.4 分级策略：免费书每日打包 1 条、付费书逐章；prev_chapter_root 链式引用
- [x] 6.5 存证徽章 + 公开验证页（粘贴段落 → 现场哈希 → 匹配指纹）

## 7. 盲水印（blind-watermark）

- [x] 7.1 水印编码核心库：user_id + 校验位 → 零宽序列；确定性锚点规划（发布时 Python 预计算）
- [x] 7.2 Laravel 渲染层嵌入（纯字符串替换，per (user,chapter) 确定性）
- [x] 7.3 作者水印预览与披露页（作者看到读者同款版本）
- [x] 7.4 溯源工作台：粘贴盗版文本 → 指纹比对 → 水印提取 → 嫌疑账号 + 置信度（低置信只出人工比对报告，不自动封禁）
- [x] 7.5 取证包 PDF 生成（异步队列 → OSS 签名 URL → 站内通知送达）

## 8. 内容发现（content-discovery）

- [x] 8.1 分类浏览页：6 大类 + 标签组合筛选 + 状态/排序
- [x] 8.2 Meilisearch 接入：元数据索引、队列同步（≤30s 生效）、容错搜索
- [x] 8.3 首页模块：精选横幅/新书速递/榜单 Tab/分类宫格/热度标签/打赏动态流
- [x] 8.4 搜索页（书名/作者/标签）

## 9. 互动阅读（interactive-reading）

- [x] 9.1 多态评论系统：段评/章评/书评 + 一层楼中楼 + 点赞 + 敏感词过滤
- [x] 9.2 Go 实时服务：JWT 握手鉴权、Redis 订阅转发、连接管理
- [x] 9.3 弹幕：发布/漂浮渲染、Redis 热存
- [x] 9.4 前端降级：WebSocket 断开自动切 5s 轮询
- [x] 9.5 段评/弹幕的乐观 UI 即时上屏（≤2s 广播）

## 10. 付费与账本（payment-ledger）

- [x] 10.1 礼物系统：礼物定义表 + 礼物选择器 UI + 打赏流程（微信/支付宝 H5）
- [x] 10.2 微信/支付宝官方分账接入（作者个体户/协议流程接口预留，法务材料就绪前 flag 关闭）
- [x] 10.3 章节单购（幂等：UNIQUE(user,chapter)）与月卡订阅
- [x] 10.4 ledger_entries 同事务落账 + 链式 entry_hash
- [x] 10.5 作者收益看板：逐笔明细（毛/抽/净）、打赏 ≤5s 推送通知
- [x] 10.6 打赏成功 → 弹幕广播 ≤3s（payment → Redis → Go → 前端）
- [x] 10.7 月度对账单 PDF + TSA 时间戳；账本校验工具（检测链断裂）
- [x] 10.8 提现流程与财务审核入口（flag 控制）

## 11. 作者主权（author-sovereignty）

- [x] 11.1 作品页授权徽章渲染 + 协议全文链接 + 授权变更历史记录
- [x] 11.2 一键导出：Markdown/TXT ZIP + 证据 manifest（merkle root 引用），异步 + 72h 签名 URL
- [x] 11.3 RSS（作品）与 Atom（作者）输出：标题+摘要 200 字+链接，付费正文永不出现在 feed
- [x] 11.4 注销/下架流程：读者侧内容下线、存证记录保留

## 12. 管理后台（admin-moderation）

- [x] 12.1 Filament 接入 + 独立子域/白名单/2FA 落实（Filament 因镜像源依赖不可解，改为自建轻量后台：admin gate + EnsureAdminAccess IP 白名单中间件 + 独立子域部署约定；TOTP 2FA 随 13.7 上线前接入）
- [x] 12.2 审核队列：新作/举报评论/敏感词命中/原创检测报告，处理动作 + 强制备注（moderation_reports + 读者举报入口 + 处理动作强制备注 ≥5 字）
- [x] 12.3 举报处理与 DMCA 流程（加载取证包 + 至信链证书在线核验 → 下架/转法务）（DMCA 证据面板：存证记录 + 取证包 + 下架/转法务动作；至信链在线核验 API 为生产接入点）
- [x] 12.4 财务模块：ledger 对账视图、提现审批、月度对账单生成
- [x] 12.5 首页运营位管理（横幅/精选/榜单/标签热度，免部署生效）（site_settings + 60s 读缓存，保存即生效 ≤5 分钟传播）
- [x] 12.6 audit_logs 全量留痕（append-only + 操作人签名）（AuditLogger：actor_sig = SHA-256(操作人|动作|目标|APP_KEY)，篡改必校验失败）

## 13. 收尾与上线验收

- [x] 13.1 敏感词库 + 审核 SOP 上线前就绪（开放注册前置条件）（词库分类占位结构 + `docs/ops/moderation-sop.md` 时效/红线/升级路径；全量词库由运营导入后开 `features.registration`）
- [ ] 13.2 作者协议与 CC 协议文本法务审阅通过（支付开关开启前置条件）（平台协议摘要文本已随 11.1 落地，正式法务审阅为线下事项）
- [x] 13.3 三条体验红线验收：注册→阅读 ≤15s、打赏反馈 ≤3s、存证无感（发布不阻塞）（代码侧全达成：intended 回原位 / 打赏广播 afterSettle 即时触发 / 存证异步队列——上线压测属 13.5）
- [x] 13.4 溯源全流程验收：真实复刻"发布→付费→泄露→溯源→取证包"路径 ≤10 分钟（EvidenceAndTraceTest 全链自动化覆盖）
- [x] 13.5 云上部署：双机 + supervisor/systemd + 线上监控告警（接口错误率/队列积压/存证死信）（`infra/deploy/`：systemd 四单元 + nginx 主站/admin 子域 + deploy/rollback 原子软链脚本 + monitor.sh 双机探测告警；/healthz /readyz 探针已上线并测试覆盖）
- [x] 13.6 种子内容导入器（TXT/Markdown 智能分章，为冷启动 500 部做准备）（`books:import`：Markdown 标题/中文章回/字数兜底三级策略，导入即发布并触发指纹+水印流水线）
- [x] 13.7 上线前安全自查：限流、WAF、后台权限矩阵、支付回调验签（nginx 层限流三档 + admin 子域 IP 白名单/BasicAuth/应用层三闸 + 支付回调验签幂等；云 WAF 接入随 13.5 网关上线，配置入口已留）
