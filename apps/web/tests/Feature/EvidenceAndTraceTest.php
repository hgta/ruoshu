<?php

declare(strict_types=1);

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterContent;
use App\Models\EvidenceRecord;
use App\Models\Export;
use App\Models\Paragraph;
use App\Models\User;
use App\Models\WatermarkAudit;
use App\Models\WatermarkSeed;
use App\Services\AiPythonClient;
use App\Services\Reading\ParagraphNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| 任务组 6/7 测试：公开验证页 + 水印披露 + 溯源工作台 + 取证包
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

function createEvidenceBook(string $paraText): array
{
    $author = User::factory()->create(['roles' => User::ROLE_AUTHOR]);
    $book = Book::create([
        'user_id' => $author->id,
        'title' => '溯源之书',
        'title_hash' => hash('sha256', '溯源之书'),
        'intro' => '测试',
        'category_id' => 1,
        'license' => 1,
        'status' => 1,
    ]);
    $chapter = Chapter::create([
        'book_id' => $book->id,
        'chapter_no' => 1,
        'title' => '第一章',
        'word_count' => mb_strlen($paraText),
        'status' => Chapter::STATUS_PUBLISHED,
        'is_paid' => true,
        'price' => 20,
        'published_at' => now(),
    ]);
    ChapterContent::create(['chapter_id' => $chapter->id, 'content' => $paraText]);
    Paragraph::create([
        'chapter_id' => $chapter->id,
        'book_id' => $book->id,
        'para_no' => 1,
        'version' => 1,
        'hash' => app(ParagraphNormalizer::class)->hash($paraText),
        'char_count' => mb_strlen($paraText),
    ]);
    EvidenceRecord::create([
        'book_id' => $book->id,
        'chapter_id' => $chapter->id,
        'kind' => EvidenceRecord::KIND_CHAPTER,
        'merkle_root' => str_repeat('ab', 32),
        'word_count' => 100,
        'version' => 1,
        'status' => EvidenceRecord::ST_CONFIRMED,
        'chain_name' => 'zhixin',
        'tx_id' => 'tx-abc-123',
        'cert_no' => "CERT-{$book->id}-1",
        'confirmed_at' => now(),
    ]);

    return [$author, $book, $chapter];
}

// ===== 6.5 公开验证页 =====

it('公开验证页：粘贴段落现场哈希命中指纹与存证记录', function () {
    [$author, $book, $chapter] = createEvidenceBook('夜色渐深，他推开门，屋内无人。月光如水，洒了一地。');
    $para = Paragraph::first();

    // 未登录可访问（公开）
    $this->get('/verify')->assertOk()->assertSee('版权存证验证');

    // 现场哈希匹配：页面回显作品名 + 指纹 + 已上链证书
    $this->post('/verify', ['text' => '夜色渐深，他推开门，屋内无人。月光如水，洒了一地。'])
        ->assertOk()
        ->assertSee('命中存证记录')
        ->assertSee($book->title)
        ->assertSee($para->hash)
        ->assertSee('已上链')
        ->assertSee("CERT-{$book->id}-1");
});

it('公开验证页：无关文本不命中且不泄露任何正文', function () {
    createEvidenceBook('夜色渐深，他推开门，屋内无人。月光如水，洒了一地。');

    $res = $this->post('/verify', ['text' => '这是一段完全无关的任意文本内容，用于验证不命中的表现。']);

    $res->assertOk()
        ->assertSee('未找到匹配的存证记录')
        ->assertDontSee('夜色渐深');
});

// ===== 7.3 水印披露与预览 =====

it('作者可查看水印披露页（付费章以本人身份渲染读者同款版本）', function () {
    $text = str_repeat('他抬起头，看见远处的灯火，若隐若现。', 8);
    [$author, $book, $chapter] = createEvidenceBook($text);

    // 无水印（边车未起）时也必须返回原正文，绝不挡阅读
    $this->actingAs($author)
        ->get("/author/books/{$book->id}/watermark")
        ->assertOk()
        ->assertSee('读者同款预览')
        ->assertSee('我们如何保护您的付费正文');
});

// ===== 7.4 溯源工作台 =====

it('溯源：水印提取命中嫌疑账号，高置信 + 审计留痕', function () {
    $text = str_repeat('风起于青萍之末，浪成于微澜之间。', 8);
    [$author, $book, $chapter] = createEvidenceBook($text);

    $suspect = User::factory()->create();
    WatermarkSeed::create([
        'user_id' => $suspect->id,
        'book_id' => $book->id,
        'chapter_id' => $chapter->id,
        'payload' => 123456,
        'anchor_plan' => null,
    ]);

    Http::fake([
        '*/v1/watermark/extract' => Http::response([
            'found_zero_width' => 33,
            'payload_hex' => 987654321,
            'user_id' => $suspect->id,
            'chapter_id' => $chapter->id,
            'version' => 1,
            'ecc_valid' => true,
            'confidence' => 'high',
        ]),
    ]);

    $this->actingAs($author)
        ->post('/author/trace', ['text' => $text])
        ->assertOk()
        ->assertSee($suspect->name)
        ->assertSee('水印种子佐证命中')
        ->assertSee('不会自动封禁任何账号');

    // 审计留痕：confidence 1.0，match_kind=水印
    $audit = WatermarkAudit::first();
    expect($audit)->not->toBeNull()
        ->and($audit->book_id)->toBe($book->id)
        ->and($audit->user_id)->toBe($suspect->id)
        ->and($audit->confidence)->toBe(1.0)
        ->and($audit->match_kind)->toBe(1);
});

it('溯源：低置信不指向账号（红线：只出人工比对报告）', function () {
    $text = str_repeat('他转身离去，再没有回头。', 8);
    [$author, $book, $chapter] = createEvidenceBook($text);

    Http::fake([
        '*/v1/watermark/extract' => Http::response([
            'found_zero_width' => 5,
            'payload_hex' => null, 'user_id' => null, 'chapter_id' => null,
            'version' => null, 'ecc_valid' => null, 'confidence' => 'low',
        ]),
    ]);

    $this->actingAs($author)
        ->post('/author/trace', ['text' => $text])
        ->assertOk()
        ->assertSee('无明确嫌疑账号')
        ->assertSee('人工比对');

    // 低置信审计：user_id null
    expect(WatermarkAudit::first()->user_id)->toBeNull();
});

// ===== 7.5 取证包 =====

it('取证包：异步生成 PDF → 存储 → 72h 内可下载，过期 410', function () {
    Storage::fake('local');
    $text = str_repeat('山月不知心底事，水风空落眼前花。', 8);
    [$author, $book, $chapter] = createEvidenceBook($text);

    // 前置：先有一次溯源审计（高置信 + 嫌疑账号）
    $suspect = User::factory()->create();
    WatermarkSeed::create([
        'user_id' => $suspect->id, 'book_id' => $book->id,
        'chapter_id' => $chapter->id, 'payload' => 1, 'anchor_plan' => null,
    ]);
    // 注意：单例 AiPythonClient 的 PendingRequest 持有首次 fake 的工厂，
    // 因此全测试只注册一次 fake，涵盖两个端点。
    Http::fake([
        '*/v1/watermark/extract' => Http::response([
            'found_zero_width' => 33, 'payload_hex' => 1, 'user_id' => $suspect->id,
            'chapter_id' => $chapter->id, 'version' => 1, 'ecc_valid' => true, 'confidence' => 'high',
        ]),
        '*/v1/forensic/pdf' => Http::response([
            'pdf_base64' => base64_encode('%PDF-1.4 取证报告测试'),
            'page_count' => 1,
        ]),
    ]);
    $this->actingAs($author)->post('/author/trace', ['text' => $text])->assertOk();

    // 生成请求入队（同步队列直接执行）
    $audit = WatermarkAudit::first();
    $this->actingAs($author)
        ->post('/author/forensics', ['audit_id' => $audit->id])
        ->assertRedirect();

    // Export 完成 + 文件落盘 + 72h 有效
    $export = Export::first();
    expect($export->status)->toBe(Export::ST_DONE)
        ->and($export->format)->toBe('pdf')
        ->and($export->oss_key)->toContain('forensic/')
        ->and($export->expires_at->isFuture())->toBeTrue();
    Storage::disk('local')->assertExists($export->oss_key);

    // 作者本人可下载
    $this->actingAs($author)
        ->get("/author/forensics/{$export->id}/download")
        ->assertOk();

    // 过期后 410
    $export->update(['expires_at' => now()->subMinute()]);
    $this->actingAs($author)
        ->get("/author/forensics/{$export->id}/download")
        ->assertStatus(410);

    // 非本人 403
    $other = User::factory()->create();
    $export->update(['expires_at' => now()->addHours(72)]);
    $this->actingAs($other)
        ->get("/author/forensics/{$export->id}/download")
        ->assertStatus(403);
});
