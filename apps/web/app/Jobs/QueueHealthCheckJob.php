<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EvidenceRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * 队列健康巡检（任务 13.5 监控挂点）：
 *  - 存证死信 > 10 → 告警
 *  - Redis 队列积压 > 1000 → 告警
 * 生产环境接钉钉/短信 webhook，MVP 先落日志。
 */
class QueueHealthCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $deadCount = EvidenceRecord::where('status', EvidenceRecord::ST_DEAD)
            ->where('updated_at', '>=', now()->subDay())
            ->count();

        if ($deadCount > 10) {
            Log::error('监控告警：存证死信超限', ['count' => $deadCount]);
            // TODO: 钉钉 webhook 告警（任务 13.5）
        }

        try {
            $pending = (int) Redis::llen('queues:default');
            if ($pending > 1000) {
                Log::warning('监控告警：队列积压', ['pending' => $pending]);
            }
        } catch (\Throwable) {
            // Redis 不可用由 readyz 层面告警，此处不重复
        }
    }
}
