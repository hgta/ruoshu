<?php

declare(strict_types=1);

use App\Jobs\GenerateMonthlyStatementJob;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterContent;
use App\Models\ChapterPurchase;
use App\Models\Donation;
use App\Models\Gift;
use App\Models\LedgerEntry;
use App\Models\MonthlyStatement;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\AiPythonClient;
use App\Services\Payment\LedgerService;
use App\Services\Payment\LedgerVerifier;
use App\Services\Payment\PaymentService;
use App\Services\Payment\TsaService;
use App\Services\Reading\AccessService;
use App\Services\Realtime\RealtimeBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| 任务组 10 付费与账本：打赏/单购/月卡/账本/看板/对账单/提现
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

function makePaidAuthorBook(): array
{
    $author = User::factory()->create(['roles' => User::ROLE_AUTHOR, 'name' => '苦写作者']);
    $book = Book::create([
        'user_id' => $author->id, 'title' => '账本之书', 'title_hash' => hash('sha256', uniqid()),
        'intro' => 'i', 'category_id' => 1, 'license' => 1, 'status' => 1,
    ]);
    $chapter = Chapter::create([
        'book_id' => $book->id, 'chapter_no' => 4, 'title' => 'VIP章',
        'word_count' => 10, 'status' => Chapter::STATUS_PUBLISHED,
        'is_paid' => true, 'price' => 200, 'published_at' => now(),
    ]);
    ChapterContent::create(['chapter_id' => $chapter->id, 'content' => '付费正文']);

    return [$author, $book, $chapter];
}

beforeEach(function () {
    config(['features.payment' => true]);
});

// ===== 10.1 礼物打赏全链路 =====

it('礼物打赏：下单 → 回调结算 → 同事务落账（毛/抽/净 5%）→ 幂等重放不重复入账', function () {
    [$author, $book, $chapter] = makePaidAuthorBook();
    $gift = Gift::create(['name' => '小星星', 'icon' => '⭐', 'price' => 500, 'sort' => 1]);
    $reader = User::factory()->create();

    // 下单
    $res = $this->actingAs($reader)->postJson('/donate', [
        'book_id' => $book->id, 'chapter_id' => $chapter->id, 'gift_id' => $gift->id, 'channel' => 'wechat',
    ])->assertCreated();
    $tradeNo = $res->json('trade_no');

    expect(Donation::where('pay_trade_no', $tradeNo)->first()->status)->toBe(Donation::ST_UNPAID);

    // 回调结算
    $this->postJson("/pay/{$tradeNo}/callback")
        ->assertOk()->assertJson(['code' => 0, 'settled' => true]);

    $entry = LedgerEntry::where('source_type', 'donation')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->gross)->toBe(500)
        ->and($entry->fee)->toBe(25)          // 5% 抽成
        ->and($entry->net)->toBe(475)
        ->and($entry->prev_hash)->toBeNull()  // 首条
        ->and(Donation::first()->status)->toBe(Donation::ST_PAID);

    // 幂等：重复回调不再落账
    $this->postJson("/pay/{$tradeNo}/callback")->assertOk()->assertJson(['settled' => false]);
    expect(LedgerEntry::count())->toBe(1);
});

it('打赏结算触发弹幕特效广播（≤3s）与作者到账通知（≤5s）', function () {
    [$author, $book, $chapter] = makePaidAuthorBook();
    $gift = Gift::create(['name' => '月亮', 'icon' => '🌙', 'price' => 1000, 'sort' => 1]);
    $reader = User::factory()->create();

    $broadcaster = $this->mock(RealtimeBroadcaster::class);
    $broadcaster->shouldReceive('toChapter')
        ->once()
        ->with($book->id, $chapter->id, 'donation', Mockery::on(fn ($p) => $p['gift'] === '月亮' && $p['amount'] === 1000));
    $broadcaster->shouldReceive('toAuthor')
        ->once()
        ->with($author->id, 'donation_paid', Mockery::on(fn ($p) => $p['amount'] === 1000));

    $res = $this->actingAs($reader)->postJson('/donate', [
        'book_id' => $book->id, 'chapter_id' => $chapter->id, 'gift_id' => $gift->id, 'channel' => 'alipay',
    ])->assertCreated();

    $this->postJson('/pay/'.$res->json('trade_no').'/callback')->assertOk();
});

it('payment flag 关闭时打赏入口 503（法务前置红线）', function () {
    config(['features.payment' => false]);
    [$author, $book] = makePaidAuthorBook();

    $this->actingAs(User::factory()->create())
        ->postJson('/donate', ['book_id' => $book->id, 'channel' => 'wechat'])
        ->assertStatus(503);
});

// ===== 10.3 章节单购 / 月卡 =====

it('单购：结算后即解锁（AccessService 生效）+ 15% 抽成 + 复购幂等返回已购单', function () {
    [$author, $book, $chapter] = makePaidAuthorBook();
    $reader = User::factory()->create();
    $access = app(AccessService::class);

    expect($access->canRead($reader->refresh(), $chapter))->toBeFalse();

    $res = $this->actingAs($reader)->postJson("/pay/purchase/{$chapter->id}", ['channel' => 'wechat'])
        ->assertCreated()->assertJson(['paid' => false]);
    $this->postJson('/pay/'.$res->json('trade_no').'/callback')->assertOk();

    expect($access->canRead($reader->refresh(), $chapter))->toBeTrue();
    $entry = LedgerEntry::where('source_type', 'purchase')->first();
    expect($entry->fee)->toBe(30)->and($entry->net)->toBe(170); // 200 × 15%

    // 复购：幂等返回已购
    $again = $this->actingAs($reader)->postJson("/pay/purchase/{$chapter->id}", ['channel' => 'wechat'])
        ->assertOk()->assertJson(['paid' => true]);
    expect(ChapterPurchase::count())->toBe(1);
});

it('月卡：结算后 30 天有效，覆盖该书 VIP 章节阅读', function () {
    [$author, $book, $chapter] = makePaidAuthorBook();
    $reader = User::factory()->create();
    $access = app(AccessService::class);

    $res = $this->actingAs($reader)->postJson("/pay/subscribe/{$book->id}", ['channel' => 'alipay'])
        ->assertCreated();
    $this->postJson('/pay/'.$res->json('trade_no').'/callback')->assertOk();

    $sub = Subscription::first();
    expect($sub->status)->toBe(Subscription::ST_ACTIVE)
        ->and($sub->ends_at->diffInDays(now()))->toBeLessThanOrEqual(30)
        ->and($access->canRead($reader->refresh(), $chapter))->toBeTrue();

    $entry = LedgerEntry::where('source_type', 'subscription')->first();
    expect($entry->fee)->toBe(300)->and($entry->net)->toBe(2700); // 3000 × 10%
});

// ===== 10.4/10.7 账本链校验 =====

it('账本校验：完整链通过，篡改净得后检出断裂', function () {
    [$author, $book, $chapter] = makePaidAuthorBook();
    $reader = User::factory()->create();

    app(PaymentService::class)->createPurchase($reader->refresh(), $chapter, 'wechat');
    $this->postJson('/pay/'.ChapterPurchase::first()->pay_trade_no.'/callback')->assertOk();

    $verifier = app(LedgerVerifier::class);
    expect($verifier->verify()['ok'])->toBeTrue();

    // 篡改：净得被改小 1 分
    LedgerEntry::first()->update(['net' => 169]);
    $report = $verifier->verify();
    expect($report['ok'])->toBeFalse()
        ->and($report['broken_at'])->toBe(LedgerEntry::first()->id);
});

it('连续结算形成链：第二条 prev_hash 指向第一条', function () {
    [$author, $book, $chapter] = makePaidAuthorBook();
    $reader = User::factory()->create();

    foreach (range(1, 2) as $i) {
        $d = app(PaymentService::class)->createDonation($reader->refresh(), $book, 'wechat', null, 100 + $i);
        $this->postJson('/pay/'.$d->pay_trade_no.'/callback')->assertOk();
    }

    $entries = LedgerEntry::orderBy('id')->get();
    expect($entries[1]->prev_hash)->toBe($entries[0]->entry_hash);
});

// ===== 10.5 收益看板 =====

it('作者收益看板：汇总（毛/抽/净）与可提现余额正确', function () {
    [$author, $book, $chapter] = makePaidAuthorBook();
    $reader = User::factory()->create();

    $d = app(PaymentService::class)->createDonation($reader->refresh(), $book, 'wechat', null, 1000);
    $this->postJson('/pay/'.$d->pay_trade_no.'/callback')->assertOk();

    $this->actingAs($author)->get('/author/finance')
        ->assertOk()
        ->assertSee('¥10.00')   // 毛额
        ->assertSee('¥9.50');   // 净得
});

// ===== 10.7 月度对账单 =====

it('月度对账单：汇总 → PDF → TSA（本地降级）→ 状态完成', function () {
    [$author, $book, $chapter] = makePaidAuthorBook();
    $reader = User::factory()->create();

    $d = app(PaymentService::class)->createDonation($reader->refresh(), $book, 'wechat', null, 2000);
    $this->postJson('/pay/'.$d->pay_trade_no.'/callback')->assertOk();

    $this->mock(AiPythonClient::class)
        ->shouldReceive('statementPdf')->once()->andReturnUsing(function (array $st) {
            expect($st['summary']['net'])->toBe(1900); // 边车收到正确汇总

            return '%PDF-1.4 mock';
        });

    $period = now()->format('Y-m');
    (new GenerateMonthlyStatementJob($author->id, $period))->handle(
        app(AiPythonClient::class),
        app(TsaService::class),
    );

    $statement = MonthlyStatement::first();
    expect($statement->status)->toBe(MonthlyStatement::ST_DONE)
        ->and($statement->gross)->toBe(2000)
        ->and($statement->net)->toBe(1900)
        ->and($statement->entry_count)->toBe(1)
        ->and($statement->tsa_source)->toBe('local')
        ->and($statement->pdf_path)->not->toBeNull();
});

// ===== 10.8 提现 =====

it('提现：flag 关闭 503；开启后校验余额；admin 审核留痕', function () {
    [$author, $book, $chapter] = makePaidAuthorBook();
    $reader = User::factory()->create();
    $admin = User::factory()->create(['roles' => User::ROLE_ADMIN]);

    // 入账：¥1000 打赏 → 作者净得 ¥950
    $d = app(PaymentService::class)->createDonation($reader->refresh(), $book, 'wechat', null, 100000);
    $this->postJson('/pay/'.$d->pay_trade_no.'/callback')->assertOk();

    // flag 关闭
    config(['features.withdrawal' => false]);
    $this->actingAs($author)->postJson('/author/withdrawals', ['amount' => 50000])
        ->assertStatus(503);

    // flag 开启：超额拒绝
    config(['features.withdrawal' => true]);
    $this->actingAs($author)->postJson('/author/withdrawals', ['amount' => 99000000])
        ->assertStatus(422);

    // 正常申请（¥500，余额 ¥950）
    $this->actingAs($author)->postJson('/author/withdrawals', ['amount' => 50000])
        ->assertCreated();
    $wd = Withdrawal::first();
    expect($wd->status)->toBe(Withdrawal::ST_PENDING);

    // 申请后余额锁定：再申请 ¥500 超余额失败
    $this->actingAs($author)->postJson('/author/withdrawals', ['amount' => 50000])
        ->assertStatus(422);

    // admin 审核通过 → 标记打款，全程 audit 留痕
    $this->actingAs($admin)->post("/admin/withdrawals/{$wd->id}/approve")->assertRedirect();
    $this->actingAs($admin)->post("/admin/withdrawals/{$wd->id}/paid")->assertRedirect();

    expect($wd->refresh()->status)->toBe(Withdrawal::ST_PAID)
        ->and(AuditLog::whereIn('action', ['withdrawal.approve', 'withdrawal.paid'])->count())->toBe(2);
});

it('提现驳回必须备注，且被拒金额释放回余额', function () {
    [$author, $book] = makePaidAuthorBook();
    $reader = User::factory()->create();
    $admin = User::factory()->create(['roles' => User::ROLE_ADMIN]);
    config(['features.withdrawal' => true]);

    $d = app(PaymentService::class)->createDonation($reader->refresh(), $book, 'wechat', null, 100000);
    $this->postJson('/pay/'.$d->pay_trade_no.'/callback')->assertOk();

    $this->actingAs($author)->postJson('/author/withdrawals', ['amount' => 95000])->assertCreated();
    $wd = Withdrawal::first();

    // 无备注驳回被验证拦截
    $this->actingAs($admin)->post("/admin/withdrawals/{$wd->id}/reject", ['note' => ''])->assertRedirect();
    expect($wd->refresh()->status)->toBe(Withdrawal::ST_PENDING);

    // 带备注驳回
    $this->actingAs($admin)->post("/admin/withdrawals/{$wd->id}/reject", ['note' => '收款账号待核实'])->assertRedirect();
    expect($wd->refresh()->status)->toBe(Withdrawal::ST_REJECTED)
        ->and(app(LedgerService::class)->withdrawableBalance($author->id))->toBe(95000);
});

it('礼物列表公开可读（打赏面板数据源）', function () {
    Gift::create(['name' => '海洋之心', 'icon' => '💎', 'price' => 5000, 'sort' => 2]);
    Gift::create(['name' => '禁用礼物', 'icon' => '🚫', 'price' => 1, 'sort' => 1, 'enabled' => false]);

    $this->getJson('/gifts')->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['name' => '海洋之心']);
});
