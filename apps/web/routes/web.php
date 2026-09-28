<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ModerationController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\WithdrawalController as AdminWithdrawalController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\AuthorVerificationController;
use App\Http\Controllers\Author\BookController;
use App\Http\Controllers\Author\ChapterController;
use App\Http\Controllers\Author\ExportController;
use App\Http\Controllers\Author\FinanceController;
use App\Http\Controllers\Author\ForensicController;
use App\Http\Controllers\Author\TraceController;
use App\Http\Controllers\Author\WatermarkController;
use App\Http\Controllers\Author\WithdrawalController as AuthorWithdrawalController;
use App\Http\Controllers\Ops\HealthController;
use App\Http\Controllers\Payment\PayController;
use App\Http\Controllers\Reading\BookController as PublicBookController;
use App\Http\Controllers\Reading\BookshelfController;
use App\Http\Controllers\Reading\ChapterController as ReadingChapterController;
use App\Http\Controllers\Reading\CommentController;
use App\Http\Controllers\Reading\DanmuController;
use App\Http\Controllers\Reading\FeedController;
use App\Http\Controllers\Reading\HomeController;
use App\Http\Controllers\Reading\LicenseController;
use App\Http\Controllers\Reading\RealtimeTokenController;
use App\Http\Controllers\Reading\ReportController;
use App\Http\Controllers\Reading\SearchController;
use App\Http\Controllers\Reading\VerifyController;
use App\Http\Middleware\EnsureAdminAccess;
use Illuminate\Support\Facades\Route;

// ===== 读者侧（公开）=====
Route::get('/', HomeController::class)->name('home');

// ===== 探针（任务 13.5：LB/监控，公开无状态）=====
Route::get('/healthz', [HealthController::class, 'healthz']);
Route::get('/readyz', [HealthController::class, 'readyz']);
Route::get('/explore', [PublicBookController::class, 'explore'])->name('explore');
Route::get('/search', SearchController::class)->name('search');
Route::get('/books/{book}', [PublicBookController::class, 'show'])->name('books.show');
Route::get('/read/{chapter}', [ReadingChapterController::class, 'read'])
    ->whereNumber('chapter')->name('chapters.read');

// ===== 版权存证公开验证（任务 6.5）=====
Route::get('/verify', [VerifyController::class, 'show'])->name('verify');
Route::post('/verify', [VerifyController::class, 'check'])->name('verify.check');

// ===== 作者主权公开输出（任务 11.1/11.3）=====
Route::get('/books/{book}/license', [LicenseController::class, 'show'])->name('books.license');
Route::get('/books/{book}/rss', [FeedController::class, 'bookRss'])->name('books.rss');
Route::get('/authors/{author}/atom', [FeedController::class, 'authorAtom'])->name('authors.atom');

// ===== 认证 =====
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::get('/auth/wechat/callback', [AuthController::class, 'wechatCallback'])->name('auth.wechat.callback');
Route::post('/login', [AuthController::class, 'login'])->name('auth.password');
Route::post('/register', [AuthController::class, 'register'])->name('register');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// ===== 弹幕（任务 9.3：列表公开，发布走 auth 组）=====
Route::get('/danmu', [DanmuController::class, 'index']);

// ===== 礼物列表（阅读页打赏面板，公开只读；pay/donate 硬闸由 FeatureFlagGate 把守）=====
Route::get('/gifts', [PayController::class, 'gifts']);

// ===== 登录后（读者）=====
Route::middleware('auth')->group(function () {
    Route::get('/me/bookshelf', [BookshelfController::class, 'index'])->name('me.bookshelf');
    Route::post('/me/bookshelf/toggle', [BookshelfController::class, 'toggle']);
    Route::post('/me/progress', [BookshelfController::class, 'reportProgress']);

    // 多态评论（任务 9.1：段评/章评/书评 + 楼中楼 + 点赞）
    Route::get('/comments', [CommentController::class, 'index']);
    Route::post('/comments', [CommentController::class, 'store']);
    Route::get('/comments/{comment}/replies', [CommentController::class, 'replies']);
    Route::post('/comments/{comment}/like', [CommentController::class, 'like']);
    Route::delete('/comments/{comment}', [CommentController::class, 'destroy']);

    // WebSocket 握手令牌（任务 9.2）
    Route::get('/realtime/token', RealtimeTokenController::class);

    // 弹幕发布（任务 9.3）
    Route::post('/danmu', [DanmuController::class, 'store']);

    // 举报（任务 12.2：审核队列数据源，登录担责）
    Route::post('/report', [ReportController::class, 'store']);

    // 作者实名认证
    Route::get('/author/verify', [AuthorVerificationController::class, 'show']);
    Route::post('/author/verify', [AuthorVerificationController::class, 'submit']);

    // ===== 支付下单（任务 10.1/10.3：FeatureFlagGate 对 pay/*、donate* 做 payment flag 硬闸）=====
    Route::post('/donate', [PayController::class, 'donate']);
    Route::post('/pay/purchase/{chapter}', [PayController::class, 'purchase'])
        ->whereNumber('chapter');
    Route::post('/pay/subscribe/{book}', [PayController::class, 'subscribe']);
});

// 支付渠道回调（服务间调用，无 session）：CSRF 已豁免（验签代替），结算幂等重放安全
Route::get('/pay/{tradeNo}', [PayController::class, 'cashier']);
Route::post('/pay/{tradeNo}/callback', [PayController::class, 'callback']);
Route::get('/pay/{tradeNo}/status', [PayController::class, 'status']);

// ===== 作者工作台 =====
Route::middleware(['auth', 'can:author'])->prefix('author')->group(function () {
    Route::get('/dashboard', fn () => view('author.dashboard'))->name('author.dashboard');

    Route::get('/books', [BookController::class, 'index'])->name('author.books');
    Route::get('/books/create', [BookController::class, 'create']);
    Route::post('/books', [BookController::class, 'store']);
    Route::get('/books/{book}/edit', [BookController::class, 'edit']);
    Route::put('/books/{book}', [BookController::class, 'update']);

    Route::get('/books/{book}/chapters', [ChapterController::class, 'index']);
    Route::get('/books/{book}/chapters/edit/{chapter?}', [ChapterController::class, 'edit'])
        ->whereNumber('chapter');
    Route::post('/books/{book}/chapters/autosave', [ChapterController::class, 'autosave']);
    Route::post('/books/{book}/chapters/{chapter}/publish', [ChapterController::class, 'publish']);
    Route::post('/books/{book}/chapters/{chapter}/revise', [ChapterController::class, 'revise']);

    // 水印披露与预览（任务 7.3）
    Route::get('/books/{book}/watermark', [WatermarkController::class, 'show'])
        ->name('author.watermark');

    // 溯源工作台（任务 7.4）
    Route::get('/trace', [TraceController::class, 'show'])->name('author.trace');
    Route::post('/trace', [TraceController::class, 'check']);

    // 取证包（任务 7.5）
    Route::get('/forensics', [ForensicController::class, 'index'])->name('author.forensics');
    Route::post('/forensics', [ForensicController::class, 'store']);
    Route::get('/forensics/{export}/download', [ForensicController::class, 'download'])
        ->whereNumber('export');

    // 收益看板（任务 10.5：逐笔毛/抽/净 + 汇总 + 月度对账单下载）
    Route::get('/finance', [FinanceController::class, 'index'])->name('author.finance');
    Route::get('/finance/statements/{statement}/download', [FinanceController::class, 'downloadStatement'])
        ->whereNumber('statement')->name('author.finance.statements.download');

    // 提现（任务 10.8：features.withdrawal flag 控制）
    Route::get('/withdrawals', [AuthorWithdrawalController::class, 'index'])->name('author.withdrawals');
    Route::post('/withdrawals', [AuthorWithdrawalController::class, 'store']);

    // 一键导出（任务 11.2：Markdown/TXT ZIP + 证据 manifest，异步 + 72h）
    Route::get('/exports', [ExportController::class, 'index'])->name('author.exports');
    Route::post('/exports', [ExportController::class, 'store']);
    Route::get('/exports/{export}/download', [ExportController::class, 'download'])
        ->whereNumber('export');

    // 一键下架（任务 11.4：读者侧下线，存证保留）
    Route::post('/books/{book}/offline', [BookController::class, 'takeOffline']);
});

// ===== 管理后台（任务 12.x：生产挂 admin 独立子域 + 网关白名单，EnsureAdminAccess 应用层双保险）=====
Route::middleware(['auth', 'can:admin', EnsureAdminAccess::class])
    ->prefix('admin')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('admin.dashboard');
        Route::get('/audits', [DashboardController::class, 'audits'])->name('admin.audits');

        // 审核队列（12.2/12.3）：举报 + DMCA（处理动作强制备注）
        Route::get('/moderation', [ModerationController::class, 'index'])->name('admin.moderation');
        Route::get('/moderation/{report}', [ModerationController::class, 'show'])
            ->whereNumber('report')->name('admin.moderation.show');
        Route::post('/moderation/{report}/handle', [ModerationController::class, 'handle'])
            ->whereNumber('report');

        // 财务（12.4）：提现审核 / 账本校验 / 对账单
        Route::get('/withdrawals', [AdminWithdrawalController::class, 'index'])->name('admin.withdrawals');
        Route::post('/withdrawals/{withdrawal}/approve', [AdminWithdrawalController::class, 'approve'])->whereNumber('withdrawal');
        Route::post('/withdrawals/{withdrawal}/reject', [AdminWithdrawalController::class, 'reject'])->whereNumber('withdrawal');
        Route::post('/withdrawals/{withdrawal}/paid', [AdminWithdrawalController::class, 'markPaid'])->whereNumber('withdrawal');
        Route::post('/statements/generate', [AdminWithdrawalController::class, 'generateStatements']);
        Route::get('/ledger/verify', [AdminWithdrawalController::class, 'verifyLedger'])->name('admin.ledger.verify');

        // 运营位（12.5：保存即生效）
        Route::get('/settings', [SettingController::class, 'edit'])->name('admin.settings');
        Route::post('/settings', [SettingController::class, 'update']);
    });
