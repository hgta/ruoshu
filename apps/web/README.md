# apps/web — Laravel 11 主站

**负责**：业务页面、API、作品/章节/支付/分账/存证调度、admin
**不负责**：长连接广播、文本处理 NLP

## 启动（本地）

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve
```

## 关键依赖

- `laravel/framework` 11.x
- `livewire/inertia` (前端交互，按需)
- `filament/filament` 3.x (后台)
- `laravel/scout` + `meilisearch/meilisearch-php` (检索)
- `predis/predis` 或 phpredis 扩展
- `bavix/laravel-wallet` 或自研分账账本（建议自研，单一真源）

## 目录约定

```
app/
├─ Domain/           业务领域（Book, Chapter, Reading, Author, Payment, Evidence, Watermark, AuthorSovereignty）
├─ Http/Controllers/
├─ Http/Middleware/
├─ Jobs/             异步任务（存证上链、水印种子生成、导出打包、月度对账单）
├─ Services/         跨领域服务（链驱动、支付分账、文本处理客户端）
├─ Models/
└─ Providers/
database/migrations/
resources/views/
routes/
tests/Feature/       Pest 功能测试
tests/Unit/          Pest 单元测试
```

## 测试

- PHPStan level 6
- Pint（代码风格）
- Pest（功能与单元测试）

## 文档

- 业务规则细节：见 `openspec/changes/add-ruoshu-mvp/specs/` 各能力规格
- 数据库设计：`docs/design/08-数据库ER图.md`