<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EvidenceRecord;
use App\Services\Evidence\EvidenceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 免费书每日打包存证：把昨日新增免费章节的 merkle root 聚合成一个批次上链。
 * 至信链分级成本策略（免费 1 条/天 vs 付费逐章）。
 */
class BatchDailyEvidenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function handle(EvidenceService $evidence): void
    {
        // 昨日免费章节 roots 登记表（FingerprintChapterJob 写入）
        $registryKey = 'daily-evidence-roots:'.now()->subDay()->format('Ymd');
        $roots = cache()->pull($registryKey, []);

        if (empty($roots)) {
            return;
        }

        // 批次 Merkle：排序去重后二次哈希
        $roots = array_values(array_unique($roots));
        sort($roots);
        $batchRoot = hash('sha256', implode('|', $roots));

        $record = $evidence->createLocalRecord(
            bookId: null, // 批次级别，无单一 book
            chapterId: null,
            kind: EvidenceRecord::KIND_DAILY_BATCH,
            merkleRoot: $batchRoot,
            meta: ['payload' => ['roots' => $roots]],
        );

        SubmitEvidenceJob::dispatch($record->id);

        Log::info('免费书日批存证已排队', ['roots' => count($roots)]);
    }
}
