<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EvidenceRecord;
use App\Services\Evidence\EvidenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 存证上链任务：本地已持久化 → 提交至信链 → tx 回写。
 * 重试 10 次后进死信（表 status=dead + 告警），数据永不丢。
 */
class SubmitEvidenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public int $backoff = 60;

    public function __construct(public int $evidenceRecordId) {}

    public function handle(EvidenceService $evidence): void
    {
        $record = EvidenceRecord::findOrFail($this->evidenceRecordId);

        if ($record->status === EvidenceRecord::ST_CONFIRMED) {
            return; // 幂等
        }

        try {
            $evidence->submitToChain($record);
            Log::info('存证上链确认', ['record' => $record->id, 'tx' => $record->tx_id]);
        } catch (\Throwable $e) {
            $record->retry_count++;
            $record->status = EvidenceRecord::ST_RETRYING;
            $record->save();

            if ($record->retry_count >= $this->tries) {
                $record->status = EvidenceRecord::ST_DEAD;
                $record->save();
                // 死信告警（任务 13.5 监控挂点）
                Log::error('存证上链死信', ['record' => $record->id, 'err' => $e->getMessage()]);
            }

            throw $e;
        }
    }
}
