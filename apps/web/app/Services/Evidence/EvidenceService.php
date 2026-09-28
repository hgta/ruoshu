<?php

declare(strict_types=1);

namespace App\Services\Evidence;

use App\Models\EvidenceRecord;
use Illuminate\Support\Facades\Storage;

/**
 * 存证服务：本地先行持久化 → 异步提交链 → tx 回写 → 重试/死信。
 * 免费书每日打包 1 条（kind=2）；付费书逐章（kind=1）。
 * 章节链式引用（prev_chapter_root）在此维护。
 */
class EvidenceService
{
    public function __construct(
        private readonly ChainDriver $driver,
    ) {}

    /** 同步：创建本地证据记录（发布事务内调用，绝不丢） */
    public function createLocalRecord(
        ?int $bookId,
        ?int $chapterId,
        int $kind,
        string $merkleRoot,
        array $meta = [],
    ): EvidenceRecord {
        $prevRoot = null;
        if ($chapterId !== null) {
            $prevRoot = EvidenceRecord::where('book_id', $bookId)
                ->where('kind', EvidenceRecord::KIND_CHAPTER)
                ->where('id', '!=', 0)
                ->latest('id')
                ->value('merkle_root');
        }

        $record = EvidenceRecord::create([
            'book_id' => $bookId,
            'chapter_id' => $chapterId,
            'kind' => $kind,
            'title_hash' => $meta['title_hash'] ?? null,
            'merkle_root' => $merkleRoot,
            'word_count' => $meta['word_count'] ?? null,
            'prev_chapter_root' => $prevRoot,
            'version' => $meta['version'] ?? 1,
            'status' => EvidenceRecord::ST_PENDING,
            'payload' => $meta['payload'] ?? null,
        ]);

        // 本地先行：payload 留底落盘（开发 local / 生产 OSS，任务 6.3）
        // 失败不影响主流程——DB 里的 payload 列已保证不丢。
        try {
            $path = "evidence/{$record->id}.json";
            Storage::disk(config('services.forensic.disk', 'local'))
                ->put($path, json_encode([
                    'id' => $record->id,
                    'book_id' => $bookId,
                    'chapter_id' => $chapterId,
                    'kind' => $kind,
                    'merkle_root' => $merkleRoot,
                    'prev_chapter_root' => $prevRoot,
                    'meta' => $meta,
                    'created_at' => now()->toIso8601String(),
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $record->payload = array_merge((array) ($record->payload ?? []), ['local_backup' => $path]);
            $record->save();
        } catch (\Throwable) {
            // 留底失败：队列重试兜底，不阻塞发布事务
        }

        return $record;
    }

    /** 异步：提交至链（Job 调用），失败由队列重试，超限进死信 */
    public function submitToChain(EvidenceRecord $record): void
    {
        $record->status = EvidenceRecord::ST_SUBMITTED;
        $record->chain_name = $this->driver->name();
        $record->submitted_at = now();
        $record->save();

        $txId = $this->driver->submit([
            'merkle_root' => $record->merkle_root,
            'title_hash' => $record->title_hash,
            'book_id' => $record->book_id,
            'chapter_id' => $record->chapter_id,
            'version' => $record->version,
            'word_count' => $record->word_count,
            'prev_chapter_root' => $record->prev_chapter_root,
        ]);

        $record->tx_id = $txId;
        $record->status = EvidenceRecord::ST_CONFIRMED;
        $record->cert_no = $record->book_id.'-'.$record->id.'-'.$txId;
        $record->confirmed_at = now();
        $record->save();
    }
}
