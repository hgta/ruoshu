<?php

declare(strict_types=1);

use App\Jobs\FingerprintChapterJob;
use App\Jobs\PlanWatermarkJob;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterContent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| 任务 13.6 种子内容导入器：TXT/Markdown 智能分章
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

it('TXT 按"第X章"标题智能分章，导入即发布并触发指纹/水印流水线', function () {
    Queue::fake();
    $author = User::factory()->create(['roles' => User::ROLE_AUTHOR]);

    $txt = "第1章 初入江湖\n\n少年背起行囊，踏出山门。\n\n晨光熹微。\n\n"
        ."第2章 风雨欲来\n\n客栈里人声鼎沸，他找到一个角落坐下。\n\n"
        ."第3章 剑出鞘\n\n三尺青锋，一剑破空。\n\n";
    $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'seed-'.uniqid().'.txt';
    file_put_contents($file, $txt);

    $this->artisan('books:import', ['file' => $file, '--author-id' => $author->id])
        ->expectsOutputToContain('导入完成')
        ->assertSuccessful();

    $book = Book::first();
    expect($book->chapter_count)->toBe(3)
        ->and($book->word_count)->toBeGreaterThan(20)
        ->and(Chapter::count())->toBe(3)
        ->and(ChapterContent::count())->toBe(3);

    $ch2 = Chapter::where('chapter_no', 2)->first();
    expect($ch2->title)->toBe('第2章 风雨欲来')
        ->and($ch2->status)->toBe(Chapter::STATUS_PUBLISHED)
        ->and($ch2->published_at)->not->toBeNull()
        ->and($ch2->content->content)->toContain('客栈里人声鼎沸');

    // 与正常发布一致的流水线
    Queue::assertPushed(FingerprintChapterJob::class, 3);
    Queue::assertPushed(PlanWatermarkJob::class, 3);

    @unlink($file);
});

it('Markdown 按 # 标题分章；无标题时按字数兜底分块', function () {
    Queue::fake();
    $author = User::factory()->create(['roles' => User::ROLE_AUTHOR]);

    $md = "# 第一章 起点\n\n长风几万里。\n\n## 第二章 转折\n\n他忽然明白了。\n\n";
    $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'md-'.uniqid().'.md';
    file_put_contents($file, $md);

    $this->artisan('books:import', ['file' => $file, '--author-id' => $author->id])
        ->assertSuccessful();

    $titles = Chapter::orderBy('chapter_no')->pluck('title')->all();
    expect($titles)->toBe(['第一章 起点', '第二章 转折']);
    @unlink($file);

    // 无任何标题 → 字数兜底（20 字/块）
    $plain = str_repeat('墨色浓稠的字。', 8); // 48 字
    $file2 = sys_get_temp_dir().DIRECTORY_SEPARATOR.'plain-'.uniqid().'.txt';
    file_put_contents($file2, $plain);

    $this->artisan('books:import', ['file' => $file2, '--author-id' => $author->id, '--words-per-chunk' => 20])
        ->assertSuccessful();

    expect(Chapter::where('book_id', Book::latest('id')->first()->id)->count())->toBeGreaterThanOrEqual(2);
    @unlink($file2);
});

it('缺 author-id 或文件不存在时报错退出', function () {
    $this->artisan('books:import', ['file' => __FILE__])->assertFailed(); // 缺 author-id
});
