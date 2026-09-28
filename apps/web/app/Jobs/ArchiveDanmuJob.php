<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Danmu;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

/**
 * 弹幕归档：Redis 热存（15 分钟窗口）→ 批量落 MySQL。
 * 弹幕是 ephemeral 流，归档仅为历史回看；评论是 durable 资产，不经此任务。
 */
class ArchiveDanmuJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const REDIS_QUEUE_KEY = 'danmu:archive:buffer';

    public function handle(): void
    {
        $batch = Redis::lrange(self::REDIS_QUEUE_KEY, 0, 499);
        if (empty($batch)) {
            return;
        }
        Redis::ltrim(self::REDIS_QUEUE_KEY, count($batch), -1);

        $rows = array_map(fn ($json) => json_decode($json, true), $batch);
        $rows = array_filter($rows);

        if ($rows) {
            $now = now()->toDateTimeString();
            foreach (array_chunk(array_values($rows), 100) as $chunk) {
                // bulk insert（补时间戳，绕过 Eloquent 逐条 create）
                Danmu::insert(array_map(
                    fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now],
                    $chunk,
                ));
            }
        }
    }
}
