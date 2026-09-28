<?php

declare(strict_types=1);

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterContent;
use App\Models\ChapterPurchase;
use App\Models\Danmu;
use App\Models\User;
use App\Services\Realtime\RealtimeTokenService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| 任务组 9 实时互动：弹幕 + WS 握手令牌（9.2/9.3/9.4/9.5）
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

function makeDanmuChapter(bool $paid = false): array
{
    $author = User::factory()->create(['roles' => User::ROLE_AUTHOR]);
    $book = Book::create([
        'user_id' => $author->id,
        'title' => '弹幕之书',
        'title_hash' => hash('sha256', uniqid()),
        'intro' => '测试',
        'category_id' => 1,
        'license' => 1,
        'status' => 1,
    ]);
    $chapter = Chapter::create([
        'book_id' => $book->id,
        'chapter_no' => $paid ? 4 : 1,
        'title' => $paid ? 'VIP章' : '第一章',
        'word_count' => 10,
        'status' => Chapter::STATUS_PUBLISHED,
        'is_paid' => $paid,
        'price' => $paid ? 20 : 0,
        'published_at' => now(),
    ]);
    ChapterContent::create(['chapter_id' => $chapter->id, 'content' => '正文内容']);

    return [$book, $chapter];
}

// ===== 9.2 WebSocket 握手令牌 =====

it('握手令牌：RS256 签发，uid/issuer/exp 可验签（Go 网关同规则）', function () {
    $reader = User::factory()->create();

    $res = $this->actingAs($reader)->getJson('/realtime/token')->assertOk();
    $token = $res->json('token');

    // 用导出公钥独立验签（模拟 Go 网关 verifier）
    $service = app(RealtimeTokenService::class);
    $claims = JWT::decode($token, new Key($service->publicKey(), 'RS256'));

    expect($claims->uid)->toBe($reader->id)
        ->and($claims->role)->toBe('reader')
        ->and($claims->iss)->toBe('ruoshu-realtime')
        ->and($claims->exp - $claims->iat)->toBe(60);

    // 同一密钥对幂等（二次签发仍可验）
    $token2 = $service->issue($reader);
    expect(JWT::decode($token2, new Key($service->publicKey(), 'RS256'))->uid)->toBe($reader->id);
});

it('握手令牌需要登录', function () {
    $this->getJson('/realtime/token')->assertUnauthorized();
});

// ===== 9.3 弹幕发布与热存 =====

it('弹幕：发布落库 + 热存 + 游客可拉取 + 增量轮询', function () {
    [$book, $chapter] = makeDanmuChapter();
    $reader = User::factory()->create(['name' => '弹幕君']);

    $r = $this->actingAs($reader)->postJson('/danmu', [
        'chapter_id' => $chapter->id,
        'content' => '这一段太精彩了',
    ])->assertCreated();
    expect($r->json('user'))->toBe('弹幕君')
        ->and(Danmu::count())->toBe(1)
        ->and(Danmu::first()->book_id)->toBe($book->id);

    // 游客拉取（未登录）：热存命中
    $list = $this->getJson("/danmu?chapter_id={$chapter->id}")->assertOk();
    expect($list->json('source'))->toBe('hot')
        ->and($list->json('danmu.0.content'))->toBe('这一段太精彩了');

    // 增量轮询（任务 9.4 降级路径的数据源）
    $firstId = $list->json('danmu.0.id');
    $this->actingAs($reader)->postJson('/danmu', [
        'chapter_id' => $chapter->id, 'content' => '第二条',
    ])->assertCreated();
    $inc = $this->getJson("/danmu?chapter_id={$chapter->id}&after_id={$firstId}")->assertOk();
    expect(collect($inc->json('danmu'))->pluck('content')->all())->toBe(['第二条']);
});

it('弹幕：敏感词拦截 422', function () {
    [$book, $chapter] = makeDanmuChapter();
    $reader = User::factory()->create();

    $this->actingAs($reader)->postJson('/danmu', [
        'chapter_id' => $chapter->id, 'content' => '含赌博词汇的弹幕',
    ])->assertStatus(422);
});

it('弹幕：未解锁 VIP 章节禁止发布（门禁与阅读一致）', function () {
    [, $vipChapter] = makeDanmuChapter(paid: true);
    $poor = User::factory()->create();

    $this->actingAs($poor)->postJson('/danmu', [
        'chapter_id' => $vipChapter->id, 'content' => '没买也想发',
    ])->assertStatus(403);
});

it('弹幕：已购用户可在 VIP 章节发布', function () {
    [$book, $vipChapter] = makeDanmuChapter(paid: true);
    $buyer = User::factory()->create();
    ChapterPurchase::create([
        'user_id' => $buyer->id, 'book_id' => $book->id, 'chapter_id' => $vipChapter->id,
        'price' => 20, 'pay_channel' => 'wechat', 'pay_trade_no' => 't'.uniqid(),
        'status' => ChapterPurchase::ST_PAID, 'paid_at' => now(),
    ]);

    $this->actingAs($buyer)->postJson('/danmu', [
        'chapter_id' => $vipChapter->id, 'content' => '买了就能发',
    ])->assertCreated();
});

it('弹幕：热存失效后回源 DB 并回填', function () {
    [$book, $chapter] = makeDanmuChapter();
    $reader = User::factory()->create();

    $this->actingAs($reader)->postJson('/danmu', [
        'chapter_id' => $chapter->id, 'content' => '热存会过期',
    ])->assertCreated();

    // 模拟热存过期
    Cache::forget("danmu:hot:{$chapter->id}");

    $list = $this->getJson("/danmu?chapter_id={$chapter->id}")->assertOk();
    expect($list->json('source'))->toBe('db')
        ->and($list->json('danmu.0.content'))->toBe('热存会过期')
        // 回填：再拉走热存
        ->and($this->getJson("/danmu?chapter_id={$chapter->id}")->json('source'))->toBe('hot');
});
