<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Chapter;
use App\Services\AiPythonClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 水印锚点规划任务：发布时对 VIP 章节预计算标点锚点分布。
 * 读时嵌入 = 纯字符串替换（零 NLP），p95 延迟目标 200ms 内。
 * 注：payload 是 per-user 的，锚点规划是 per-chapter 的（标点位置与用户无关）。
 */
class PlanWatermarkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $chapterId, public string $content) {}

    public function handle(AiPythonClient $ai): void
    {
        $chapter = Chapter::findOrFail($this->chapterId);

        if (! $chapter->is_paid) {
            return; // 免费章节不打水印（性能 + 无溯源需求）
        }

        // 用 user_id=0 占位规划锚点（锚点分布只取决于文本本身）
        $plan = $ai->planWatermark($this->content, 0, $chapter->id, $chapter->version);

        // 缓存 per-chapter 锚点方案（读时嵌入直接用）
        cache()->put(
            "watermark:plan:{$chapter->id}:{$chapter->version}",
            $plan,
            now()->addDays(30),
        );
    }
}
