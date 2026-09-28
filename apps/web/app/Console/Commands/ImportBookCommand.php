<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\FingerprintChapterJob;
use App\Jobs\PlanWatermarkJob;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterContent;
use App\Models\Tag;
use Illuminate\Console\Command;

/**
 * 种子内容导入器（任务 13.6）：为冷启动 500 部做准备。
 *
 * 用法：
 *   php artisan books:import path/to/novel.txt --author-id=7 --title="书名" --category=1 --tags=玄幻,热血
 *
 * 智能分章（优先级）：
 *   1. Markdown 一级/二级标题（# / ##）
 *   2. 中文章回标题（第X章/回/节、Chapter N、ChapterN）
 *   3. 兜底：按字数切块（默认 3000 字/章）
 * 导入即发布并触发指纹/水印流水线（与正常发布一致）。
 */
class ImportBookCommand extends Command
{
    protected $signature = 'books:import {file}
        {--author-id= : 归属作者 user_id（必填）}
        {--title= : 书名（缺省取文件名）}
        {--category=1 : 分类 id}
        {--tags= : 逗号分隔标签}
        {--words-per-chunk=3000 : 无标题时分块字数}';

    protected $description = '导入 TXT/Markdown 种子内容（智能分章，导入即发布）';

    private const MAX_CHAPTER_WORDS = 100000; // 单章 ≤10 万字（任务 4.2 红线）

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $authorId = (int) $this->option('author-id');

        if ($authorId <= 0) {
            $this->error('--author-id 必填');

            return self::FAILURE;
        }
        if (! is_file($file)) {
            $this->error("文件不存在: {$file}");

            return self::FAILURE;
        }

        $raw = file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            $this->error('文件为空或不可读');

            return self::FAILURE;
        }

        $title = (string) ($this->option('title') ?: pathinfo($file, PATHINFO_FILENAME));
        $text = $this->normalizeEol($raw);

        // 1. 智能分章
        $chapters = $this->splitChapters($text, (int) $this->option('words-per-chunk'));
        if ($chapters === []) {
            $this->error('未能解析出任何章节');

            return self::FAILURE;
        }

        // 2. 建书
        $book = Book::create([
            'user_id' => $authorId,
            'title' => $title,
            'title_hash' => hash('sha256', $title.'|'.uniqid()),
            'intro' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $chapters[0]['body'])), 0, 200),
            'category_id' => (int) $this->option('category'),
            'license' => Book::LICENSE_ALL_RIGHTS, // 种子内容默认保留所有权利（导入方自行调整）
            'status' => Book::STATUS_ONGOING,
            'chapter_count' => count($chapters),
            'word_count' => array_sum(array_column($chapters, 'words')),
        ]);

        // 3. 标签
        foreach (array_filter(array_map('trim', explode(',', (string) $this->option('tags')))) as $tagName) {
            $tag = Tag::firstOrCreate(['name' => $tagName], ['use_count' => 0]);
            $book->tags()->attach($tag);
            $tag->increment('use_count');
        }

        // 4. 逐章发布 + 触发与正常发布相同的指纹/水印流水线
        $conn = 0;
        foreach ($chapters as $i => $ch) {
            if ($ch['words'] > self::MAX_CHAPTER_WORDS) {
                $this->warn("第{$ch['no']}章超 10 万字上限，已截断");
                $ch['body'] = mb_substr($ch['body'], 0, self::MAX_CHAPTER_WORDS);
            }

            $chapter = Chapter::create([
                'book_id' => $book->id,
                'chapter_no' => $i + 1,
                'title' => $ch['title'],
                'word_count' => mb_strlen($ch['body']),
                'status' => Chapter::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);
            ChapterContent::create(['chapter_id' => $chapter->id, 'content' => $ch['body']]);

            FingerprintChapterJob::dispatch($chapter->id, $ch['body'], $chapter->word_count);
            PlanWatermarkJob::dispatch($chapter->id, $ch['body']);
            $conn++;
        }

        $this->info("《{$title}》导入完成：{$conn} 章，共 {$book->word_count} 字（指纹/水印流水线已排队）");

        return self::SUCCESS;
    }

    /** @return array<int, array{title:string, body:string, no:int, words:int}> */
    private function splitChapters(string $text, int $wordsPerChunk): array
    {
        // 策略 1：Markdown # / ## 标题
        if (preg_match_all('/^#{1,2}\s+(.+)$/m', $text, $m, PREG_OFFSET_CAPTURE)) {
            return $this->sliceBy($text, $m);
        }

        // 策略 2：中文章回 / Chapter N
        $pattern = '/^\s*(第[0-9零一二三四五六七八九十百千]+[章回节]|Chapter\s*\d+)[^\n]{0,50}$/mi';
        if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            return $this->sliceBy($text, $m);
        }

        // 策略 3：按字数切块
        $chunks = mb_str_split($text, $wordsPerChunk);
        $out = [];
        foreach ($chunks as $body) {
            if (trim($body) === '') {
                continue;
            }
            $out[] = ['no' => count($out) + 1, 'title' => '第'.(count($out) + 1).'节', 'body' => trim($body), 'words' => mb_strlen(trim($body))];
        }

        return $out;
    }

    /** 按标题偏移量切片（PREG_OFFSET_CAPTURE 为字节偏移，切片一律用字节运算） */
    private function sliceBy(string $text, array $matches): array
    {
        $out = [];
        $count = count($matches[0]);
        foreach ($matches[0] as $i => [$full, $offset]) {
            $end = $i + 1 < $count ? $matches[0][$i + 1][1] : strlen($text);
            $title = trim((string) preg_replace('/^#{1,2}\s+/', '', (string) $full)) ?: '第'.($i + 1).'章';
            $body = trim(substr($text, $offset + strlen((string) $full), (int) $end - $offset - strlen((string) $full)));
            if ($body === '') {
                continue; // 空章跳过
            }
            $out[] = ['no' => count($out) + 1, 'title' => $title, 'body' => $body, 'words' => mb_strlen($body)];
        }

        return $out;
    }

    private function normalizeEol(string $s): string
    {
        return str_replace(["\r\n", "\r"], "\n", $s);
    }
}
