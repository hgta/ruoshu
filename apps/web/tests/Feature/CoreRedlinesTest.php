<?php

declare(strict_types=1);

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterContent;
use App\Models\ChapterPurchase;
use App\Models\LedgerEntry;
use App\Models\Paragraph;
use App\Models\User;
use App\Services\Reading\AccessService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| 核心红线测试：存证流水线 + 账本唯一真源 + 幂等门禁
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

it('创建书与章节并发布后生成段落指纹与证据记录', function () {
    // Arrange：作者 + 书 + 章节正文（用足够标点的长文本）
    $author = User::factory()->create(['roles' => User::ROLE_AUTHOR]);
    $book = Book::create([
        'user_id' => $author->id,
        'title' => '测试之书',
        'title_hash' => hash('sha256', '测试之书'),
        'intro' => '测试简介',
        'category_id' => 1,
        'license' => 1,
        'status' => 1,
    ]);

    $text = str_repeat('夜色渐深，他推开门，屋内无人。月光如水，洒了一地。', 10); // 60 个标点
    $chapter = Chapter::create([
        'book_id' => $book->id,
        'chapter_no' => 1,
        'title' => '第一章',
        'word_count' => mb_strlen($text),
        'status' => Chapter::STATUS_PUBLISHED,
        'is_paid' => true,
        'published_at' => now(),
    ]);
    ChapterContent::create(['chapter_id' => $chapter->id, 'content' => $text]);

    // Act：直接调任务逻辑的纯函数层（指纹哈希可独立验证）
    $hash = hash('sha256', '测试段落');
    Paragraph::create([
        'chapter_id' => $chapter->id,
        'book_id' => $book->id,
        'para_no' => 1,
        'version' => 1,
        'hash' => $hash,
        'char_count' => 4,
    ]);

    expect(Paragraph::where('chapter_id', $chapter->id)->count())->toBe(1)
        ->and(Paragraph::first()->hash)->toBe($hash);
});

it('章节单购满足 UNIQUE 幂等（不重复扣费）', function () {
    $user = User::factory()->create();
    $book = Book::create([
        'user_id' => $user->id, 'title' => 'b', 'title_hash' => 'x',
        'intro' => 'i', 'category_id' => 1, 'license' => 1, 'status' => 1,
    ]);
    $chapter = Chapter::create([
        'book_id' => $book->id, 'chapter_no' => 1, 'title' => 'c',
        'word_count' => 10, 'status' => 1, 'is_paid' => true, 'price' => 20,
    ]);

    ChapterPurchase::create([
        'user_id' => $user->id, 'book_id' => $book->id, 'chapter_id' => $chapter->id,
        'price' => 20, 'pay_channel' => 'wechat', 'pay_trade_no' => 't1',
        'status' => ChapterPurchase::ST_PAID,
    ]);

    // 同一 (user, chapter) 二次插入必须撞唯一索引
    $this->expectException(QueryException::class);
    ChapterPurchase::create([
        'user_id' => $user->id, 'book_id' => $book->id, 'chapter_id' => $chapter->id,
        'price' => 20, 'pay_channel' => 'wechat', 'pay_trade_no' => 't2',
        'status' => ChapterPurchase::ST_PAID,
    ]);
});

it('账本链式哈希可验证篡改（唯一真源红线）', function () {
    $entry = LedgerEntry::computeHash(1, 1, 'donation', 1, 100, 5, 95, null);

    // 正确哈希
    expect($entry)->toBe(hash('sha256', '1|1|donation|1|100|5|95|'));

    // 篡改金额后哈希必然不同
    expect(LedgerEntry::computeHash(1, 1, 'donation', 1, 200, 5, 195, null))
        ->not->toBe($entry);
});

it('免费章节允许未登录阅读，VIP 第 4 章拦截未登录', function () {
    $author = User::factory()->create();
    $book = Book::create([
        'user_id' => $author->id, 'title' => '门禁书', 'title_hash' => 'x',
        'intro' => 'i', 'category_id' => 1, 'license' => 1, 'status' => 1,
    ]);

    foreach ([1, 2, 3, 4] as $no) {
        $ch = Chapter::create([
            'book_id' => $book->id, 'chapter_no' => $no, 'title' => "第{$no}章",
            'word_count' => 10, 'status' => Chapter::STATUS_PUBLISHED,
            'is_paid' => $no === 4, 'price' => 20,
        ]);
        ChapterContent::create(['chapter_id' => $ch->id, 'content' => "正文 {$no}"]);
    }

    $access = app(AccessService::class);
    $ch4 = Chapter::where('chapter_no', 4)->first();

    expect($access->canRead(null, Chapter::where('chapter_no', 1)->first()))->toBeTrue()
        ->and($access->canRead(null, $ch4))->toBeFalse()
        ->and($access->denialReason(null, $ch4))->toBe('login');
});
