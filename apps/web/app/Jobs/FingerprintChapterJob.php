<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Chapter;
use App\Models\EvidenceRecord;
use App\Models\Paragraph;
use App\Services\AiPythonClient;
use App\Services\Evidence\EvidenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 章节指纹任务：调 Python 边车生成分段 SHA-256 + Merkle root，
 * 与 EvidenceRecord 同步创建（本地先行，绝不阻塞发布）。
 */
class FingerprintChapterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $backoff = 30;

    public function __construct(
        public int $chapterId,
        public string $content,
        public int $wordCount,
    ) {}

    public function handle(AiPythonClient $ai, EvidenceService $evidence): void
    {
        $chapter = Chapter::findOrFail($this->chapterId);
        $book = $chapter->book;

        // 1. 分段指纹 + Merkle root（Python 边车）
        $fp = $ai->fingerprintChapter($this->content, (string) $chapter->id);

        DB::transaction(function () use ($chapter, $book, $fp, $evidence) {
            // 2. 段落指纹落库（发布后只读快照，按 version）
            foreach ($fp['paragraphs'] as $p) {
                Paragraph::updateOrCreate(
                    [
                        'chapter_id' => $chapter->id,
                        'version' => $chapter->version,
                        'para_no' => $p['para_no'],
                    ],
                    [
                        'book_id' => $book->id,
                        'hash' => $p['hash'],
                        'char_count' => $p['char_count'],
                    ]
                );
            }

            // 3. 本地证据先行持久化（逐章 or 免费书日批由 EvidenceService 策略层决定）
            $kind = $chapter->is_paid
                ? EvidenceRecord::KIND_CHAPTER
                : EvidenceRecord::KIND_DAILY_BATCH;

            if ($chapter->is_paid) {
                $record = $evidence->createLocalRecord(
                    bookId: $book->id,
                    chapterId: $chapter->id,
                    kind: $kind,
                    merkleRoot: $fp['merkle_root'],
                    meta: [
                        'title_hash' => null, // 明文标题不上链
                        'word_count' => $this->wordCount,
                        'version' => $chapter->version,
                    ],
                );

                // 4. 异步提交至信链（重试/死信由队列负责）
                SubmitEvidenceJob::dispatch($record->id)
                    ->delay(now()->addSeconds(10));
            } else {
                // 免费书：root 汇入当日登记表，由每日打包任务（02:00）聚合上链
                $registryKey = 'daily-evidence-roots:'.now()->format('Ymd');
                $registry = cache()->get($registryKey, []);
                $registry["{$book->id}:{$chapter->id}"] = $fp['merkle_root'];
                cache()->put($registryKey, $registry, now()->addDays(2));
            }
        });

        Log::info('章节指纹完成', ['chapter_id' => $chapter->id, 'root' => $fp['merkle_root']]);
    }
}
