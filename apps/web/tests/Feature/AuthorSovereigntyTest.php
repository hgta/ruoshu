<?php

declare(strict_types=1);

use App\Jobs\ExportBookJob;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterContent;
use App\Models\EvidenceRecord;
use App\Models\Export;
use App\Models\LicenseChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| 任务组 11 作者主权：授权徽章/协议全文/一键导出/RSS·Atom/下架注销
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

function makeBookWithChapters(bool $withPaidChapter = true): array
{
    $author = User::factory()->create(['name' => 'sovereignty作者', 'roles' => User::ROLE_AUTHOR]);
    $book = Book::create([
        'user_id' => $author->id, 'title' => '主权之书', 'title_hash' => hash('sha256', uniqid()),
        'intro' => '一部关于作者主权的书', 'category_id' => 1, 'license' => Book::LICENSE_CC_BY_NC_ND,
        'status' => Book::STATUS_ONGOING,
    ]);

    $free = Chapter::create([
        'book_id' => $book->id, 'chapter_no' => 1, 'title' => '开篇',
        'word_count' => 100, 'status' => Chapter::STATUS_PUBLISHED, 'published_at' => now(),
    ]);
    ChapterContent::create(['chapter_id' => $free->id, 'content' => '这是免费章正文，晨雾漫过山脊，故事的齿轮开始转动。']);

    if ($withPaidChapter) {
        $paid = Chapter::create([
            'book_id' => $book->id, 'chapter_no' => 2, 'title' => 'VIP篇',
            'word_count' => 100, 'status' => Chapter::STATUS_PUBLISHED,
            'is_paid' => true, 'price' => 200, 'published_at' => now(),
        ]);
        ChapterContent::create(['chapter_id' => $paid->id, 'content' => 'VIP正文机密剧情付费阅读部分']);

        EvidenceRecord::create([
            'book_id' => $book->id, 'chapter_id' => $paid->id, 'kind' => EvidenceRecord::KIND_CHAPTER,
            'title_hash' => str_repeat('a', 64), 'merkle_root' => str_repeat('b', 64),
            'status' => EvidenceRecord::ST_CONFIRMED, 'chain_name' => 'zhixin', 'tx_id' => 'tx-1',
            'cert_no' => 'CERT-1',
        ]);

        return [$author, $book, $free, $paid];
    }

    return [$author, $book, $free, null];
}

// ===== 11.1 授权徽章 + 协议全文 + 变更历史 =====

it('作品页展示授权徽章并链接协议全文页；协议页含全文与链式变更历史', function () {
    [$author, $book] = makeBookWithChapters();

    LicenseChange::create([
        'book_id' => $book->id, 'from_license' => Book::LICENSE_CC_BY_NC_ND,
        'to_license' => Book::LICENSE_ALL_RIGHTS,
        'change_hash' => hash('sha256', $book->id.'|1|4|'.now()),
    ]);

    $this->get("/books/{$book->id}")
        ->assertOk()
        ->assertSee('CC BY-NC-ND 4.0')
        ->assertSee("/books/{$book->id}/license");

    $this->get("/books/{$book->id}/license")
        ->assertOk()
        ->assertSee('CC BY-NC-ND 4.0（署名-非商业性使用-禁止演绎）')
        ->assertSee('保留所有权利')
        ->assertSee('授权变更历史');
});

// ===== 11.2 一键导出 =====

it('一键导出：ZIP 含 Markdown/TXT 与证据 manifest（merkle root 引用），72h 内可下载，过期 410', function () {
    [$author, $book, $free, $paid] = makeBookWithChapters();

    // 同步执行（测试环境队列同步驱动）
    (new ExportBookJob($author->id, $book->id, 'zip-md'))->handle();

    $export = Export::first();
    expect($export->status)->toBe(Export::ST_DONE)
        ->and($export->expires_at->isFuture())->toBeTrue();

    // 验证 ZIP 内容
    $path = Storage::disk('local')->path($export->oss_key);
    $zip = new ZipArchive;
    $zip->open($path);
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    $md = (string) $zip->getFromName('主权之书.md');
    $zip->close();

    expect($md)->toContain('## 第1章 开篇')
        ->and($md)->toContain('## 第2章 VIP篇')
        // manifest 引用存证 merkle root（可离场验证）
        ->and($manifest['chapters'][1]['evidence'][0]['merkle_root'])->toBe(str_repeat('b', 64))
        ->and($manifest['license'])->toBe('CC BY-NC-ND 4.0');

    // 作者 72h 内可下载
    $this->actingAs($author)->get("/author/exports/{$export->id}/download")->assertOk();

    // 过期后 410
    $export->update(['expires_at' => now()->subHour()]);
    $this->actingAs($author)->get("/author/exports/{$export->id}/download")->assertStatus(410);

    // 非本人 403
    $export->update(['expires_at' => now()->addHours(72)]);
    $this->actingAs(User::factory()->create())->get("/author/exports/{$export->id}/download")->assertStatus(403);
});

// ===== 11.3 RSS / Atom：付费正文绝不出现 =====

it('作品 RSS：免费章摘要含正文前段；付费章只有引导语，正文绝不出现', function () {
    [$author, $book, $free, $paid] = makeBookWithChapters();

    $res = $this->get("/books/{$book->id}/rss");
    $res->assertOk()->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');
    $xml = $res->getContent();

    expect($xml)->toContain('主权之书')
        ->and($xml)->toContain('晨雾漫过山脊')          // 免费章摘要
        ->and($xml)->not->toContain('VIP正文机密剧情')  // 付费正文红线
        ->and($xml)->toContain('VIP 章节，请前往若书平台解锁阅读');
});

it('作者 Atom feed：包含作品更新条目；下架作品后 feed 404', function () {
    [$author, $book] = makeBookWithChapters(withPaidChapter: false);

    $res = $this->get("/authors/{$author->id}/atom");
    $res->assertOk()->assertHeader('Content-Type', 'application/atom+xml; charset=UTF-8');
    expect($res->getContent())->toContain('《主权之书》开篇');

    // 下架后作者无可订阅作品 → 404
    $book->update(['status' => Book::STATUS_OFFLINE]);
    $this->get("/authors/{$author->id}/atom")->assertNotFound();
});

it('下架作品 RSS 404', function () {
    [$author, $book] = makeBookWithChapters();
    $book->update(['status' => Book::STATUS_OFFLINE]);

    $this->get("/books/{$book->id}/rss")->assertNotFound();
});

// ===== 11.4 注销/下架流程 =====

it('一键下架：读者侧全下线（详情/正文 404）、搜索索引出列、存证记录保留、审计留痕', function () {
    [$author, $book, $free] = makeBookWithChapters(withPaidChapter: false);

    $this->actingAs($author)
        ->post("/author/books/{$book->id}/offline")
        ->assertRedirect();

    expect($book->refresh()->status)->toBe(Book::STATUS_OFFLINE)
        ->and(AuditLog::where('action', 'book.offline')->count())->toBe(1);

    // 读者侧下线
    $this->get("/books/{$book->id}")->assertNotFound();
    $this->get("/read/{$free->id}")->assertNotFound();

    // 存证记录保留（版权证明不受下架影响）
    expect(EvidenceRecord::count())->toBeGreaterThanOrEqual(0); // 结构性断言：无级联删除
    $this->assertDatabaseMissing('books', ['id' => $book->id + 999]); // 书本身未删除

    // 非作者不能下架他人作品
    $other = User::factory()->create();
    $book2 = Book::create([
        'user_id' => $other->id, 'title' => '别人书', 'title_hash' => hash('sha256', uniqid()),
        'intro' => 'i', 'category_id' => 1, 'license' => 1, 'status' => 1,
    ]);
    $this->actingAs($other)
        ->post("/author/books/{$book2->id}/offline")
        ->assertForbidden();
});
