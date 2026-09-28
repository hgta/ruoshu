<?php

declare(strict_types=1);

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterContent;
use App\Models\Comment;
use App\Models\Donation;
use App\Models\Gift;
use App\Models\Paragraph;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| 任务组 8 内容发现 + 9.1 多态评论
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

function makeBook(User $author, array $attrs = []): Book
{
    return Book::create(array_merge([
        'user_id' => $author->id,
        'title' => '发现之书'.uniqid(),
        'title_hash' => hash('sha256', uniqid()),
        'intro' => '测试简介',
        'category_id' => 1,
        'license' => 1,
        'status' => 1,
        'view_count' => random_int(1, 100),
        'word_count' => 1000,
    ], $attrs));
}

// ===== 8.1 分类浏览 =====

it('分类浏览：分类 + 标签组合筛选 + 排序', function () {
    $author = User::factory()->create();
    $tagX = Tag::create(['name' => '穿越']);
    $tagY = Tag::create(['name' => '破案']);

    $a = makeBook($author, ['title' => '甲', 'category_id' => 1, 'view_count' => 10]);
    $b = makeBook($author, ['title' => '乙', 'category_id' => 2, 'view_count' => 50]);
    $c = makeBook($author, ['title' => '丙', 'category_id' => 1, 'view_count' => 99]);
    $a->tags()->attach($tagX);
    $b->tags()->attach($tagY);
    $c->tags()->attach($tagX);

    // 分类筛选
    $this->get('/explore?category=1')->assertOk()
        ->assertSee('甲')->assertSee('丙')->assertDontSee('乙');

    // 分类 + 标签组合
    $this->get("/explore?category=1&tag={$tagX->id}")->assertOk()
        ->assertSee('甲')->assertSee('丙');

    // 最热排序：丙在甲前
    $res = $this->get('/explore?category=1&sort=hot')->assertOk();
    $pos1 = strpos($res->getContent(), '丙');
    $pos2 = strpos($res->getContent(), '甲');
    expect($pos1)->toBeLessThan($pos2);

    // 完结筛选
    $c->update(['status' => 2]);
    $this->get('/explore?category=1&status=finished')->assertOk()
        ->assertSee('丙')->assertDontSee('甲');
});

// ===== 8.2/8.4 搜索（降级容错）=====

it('搜索：Meilisearch 不可用时降级 LIKE（书名/作者/标签）', function () {
    $someone = User::factory()->create(['name' => '白月初']);
    $other = User::factory()->create();
    $book = makeBook($someone, ['title' => '狐妖小红娘传']);
    $noise = makeBook($other, ['title' => '完全无关']);

    $tag = Tag::create(['name' => '人妖恋']);
    $tagBook = makeBook($other, ['title' => '另一本书']);
    $tagBook->tags()->attach($tag);

    // 未启动 Meilisearch → 自动降级（127.0.0.1:7700 连接拒绝）

    // 按书名
    $r1 = $this->get('/search?q='.'狐妖')->assertOk();
    $r1->assertSee('狐妖小红娘传')->assertDontSee('完全无关');

    // 按作者名
    $r2 = $this->get('/search?q='.'白月初')->assertOk();
    $r2->assertSee('狐妖小红娘传');

    // 按标签名
    $r3 = $this->get('/search?q='.'人妖恋')->assertOk();
    $r3->assertSee('另一本书');

    // LIKE 特殊字符安全（不报错）
    $this->get('/search?q=%25%25')->assertOk();
});

// ===== 8.3 首页 =====

it('首页：榜单/新书/分类宫格/热度标签/打赏动态渲染', function () {
    Cache::flush();
    $author = User::factory()->create(['name' => '首页作者']);
    $book = makeBook($author, ['title' => '首页之书', 'view_count' => 88]);
    Tag::create(['name' => '首页标签', 'use_count' => 5]);

    $gift = Gift::create(['name' => '小星星', 'icon' => '', 'price' => 100]);
    $reader = User::factory()->create(['name' => '打赏读者']);
    Donation::create([
        'user_id' => $reader->id, 'book_id' => $book->id, 'gift_id' => $gift->id,
        'amount' => 100, 'pay_channel' => 'wechat', 'pay_trade_no' => 't'.uniqid(),
        'status' => Donation::ST_PAID, 'paid_at' => now(),
    ]);

    $res = $this->get('/')->assertOk();
    $res->assertSee('首页之书')          // 榜单/新书
        ->assertSee('古风言情')          // 分类宫格
        ->assertSee('首页标签')          // 热度标签
        ->assertSee('打赏读者')          // 打赏动态
        ->assertSee('小星星');
});

// ===== 9.1 多态评论 =====

it('评论：段评/章评/书评 + 楼中楼一层限制 + 敏感词拦截', function () {
    $author = User::factory()->create();
    $book = makeBook($author);
    $chapter = Chapter::create([
        'book_id' => $book->id, 'chapter_no' => 1, 'title' => '第一章',
        'word_count' => 10, 'status' => Chapter::STATUS_PUBLISHED,
    ]);
    ChapterContent::create(['chapter_id' => $chapter->id, 'content' => '正文']);
    $para = Paragraph::create([
        'chapter_id' => $chapter->id, 'book_id' => $book->id,
        'para_no' => 1, 'version' => 1, 'hash' => str_repeat('a', 64), 'char_count' => 2,
    ]);

    $reader = User::factory()->create();

    // 章评
    $r = $this->actingAs($reader)->postJson('/comments', [
        'target_type' => 'chapter', 'target_id' => $chapter->id, 'content' => '写得好！',
    ])->assertCreated();
    $chapterCommentId = $r->json('id');

    // 段评（冗余字段正确解析）
    $this->actingAs($reader)->postJson('/comments', [
        'target_type' => 'paragraph', 'target_id' => $para->id, 'content' => '这段妙',
    ])->assertCreated();
    $paraComment = Comment::where('target_type', 'paragraph')->first();
    expect($paraComment->book_id)->toBe($book->id)
        ->and($paraComment->chapter_id)->toBe($chapter->id);

    // 楼中楼回复（一层）
    $replier = User::factory()->create();
    $this->actingAs($replier)->postJson('/comments', [
        'target_type' => 'chapter', 'target_id' => $chapter->id,
        'parent_id' => $chapterCommentId, 'content' => '同意+1',
    ])->assertCreated();
    expect(Comment::find($chapterCommentId)->reply_count)->toBe(1);

    // 楼中楼套楼中楼：禁止（parent 必须是顶层）
    $reply = Comment::where('parent_id', $chapterCommentId)->first();
    $this->actingAs($replier)->postJson('/comments', [
        'target_type' => 'chapter', 'target_id' => $chapter->id,
        'parent_id' => $reply->id, 'content' => '套娃',
    ])->assertNotFound(); // parent 带 parent_id → firstOrFail 404

    // 敏感词拦截
    $this->actingAs($reader)->postJson('/comments', [
        'target_type' => 'book', 'target_id' => $book->id, 'content' => '含赌博词汇',
    ])->assertStatus(422);
});

it('评论：点赞 toggle 与软删除', function () {
    $author = User::factory()->create();
    $book = makeBook($author);
    $reader = User::factory()->create();

    $c = Comment::create([
        'user_id' => $author->id, 'book_id' => $book->id, 'target_type' => 'book',
        'target_id' => $book->id, 'content' => '欢迎评论', 'status' => Comment::ST_NORMAL,
    ]);

    // 点赞
    $r1 = $this->actingAs($reader)->postJson("/comments/{$c->id}/like")->assertOk();
    expect($r1->json('liked'))->toBeTrue()->and($r1->json('like_count'))->toBe(1);

    // 再点 = 取消
    $r2 = $this->actingAs($reader)->postJson("/comments/{$c->id}/like")->assertOk();
    expect($r2->json('liked'))->toBeFalse()->and($r2->json('like_count'))->toBe(0);

    // 本人软删除
    $this->actingAs($author)->deleteJson("/comments/{$c->id}")->assertOk();
    expect($c->fresh()->status)->toBe(Comment::ST_DELETED);

    // 他人删除：403
    $c2 = Comment::create([
        'user_id' => $author->id, 'book_id' => $book->id, 'target_type' => 'book',
        'target_id' => $book->id, 'content' => 'x', 'status' => Comment::ST_NORMAL,
    ]);
    $this->actingAs($reader)->deleteJson("/comments/{$c2->id}")->assertStatus(403);
});
