<?php

declare(strict_types=1);

namespace App\Services\Reading;

use App\Models\Chapter;
use App\Models\User;
use App\Models\WatermarkSeed;
use App\Services\AiPythonClient;

/**
 * 付费正文 per-user 渲染：
 *  - payload = f(user_id, chapter_id, version)（WatermarkSeed 确定性存储）
 *  - 嵌入 = 调 Python embed（纯字符串替换，发布时已预计算锚点）
 *  - 免费章节直接原样返回（可走共享缓存）
 *  - 付费内容绝不进公共/共享缓存（spec 硬约束）
 */
class WatermarkService
{
    public function __construct(private readonly AiPythonClient $ai) {}

    /** 返回该用户该章节应看到的水印正文 */
    public function render(User $user, Chapter $chapter, string $content): string
    {
        if (! $chapter->is_paid) {
            return $content; // 免费章不打水印
        }

        if (! config('features.ai_watermark')) {
            return $content; // 边车未就绪降级
        }

        // 1. 确定性 payload：同 (user,chapter) 每次相同
        $seed = WatermarkSeed::where('user_id', $user->id)
            ->where('chapter_id', $chapter->id)
            ->first();

        if (! $seed) {
            try {
                $plan = $this->ai->planWatermark($content, $user->id, $chapter->id, $chapter->version);
                $seed = WatermarkSeed::create([
                    'user_id' => $user->id,
                    'book_id' => $chapter->book_id,
                    'chapter_id' => $chapter->id,
                    'payload' => $plan['payload_hex'],
                    'anchor_plan' => $plan['anchor_positions'],
                ]);
            } catch (\Throwable) {
                return $content; // 边车短暂不可用：宁可无水印也不挡阅读
            }
        }

        // 2. 嵌入（读时纯替换）
        try {
            return $this->ai->embedWatermark($content, (int) $seed->payload);
        } catch (\Throwable) {
            return $content;
        }
    }
}
