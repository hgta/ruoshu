<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * 实时广播（任务 9.2 / 9.5 / 10.6）：
 * Laravel 侧统一向 Redis Pub/Sub 发布，Go 网关按频道路由到 WebSocket 连接。
 * 频道约定（与 Go hub.routeChannel 对齐）：
 *   book:{book_id}:ch:{chapter_id}:{danmu|comment|donation}
 *   author:{author_id}:notify
 * 广播是尽力而为：Redis 不可用时只告警不阻塞业务（降级为轮询）。
 */
class RealtimeBroadcaster
{
    /** 章节内广播（弹幕/段评/打赏特效） */
    public function toChapter(int $bookId, int $chapterId, string $kind, array $payload): void
    {
        $this->publish(
            "book:{$bookId}:ch:{$chapterId}:{$kind}",
            ['type' => $kind, 'payload' => $payload],
        );
    }

    /** 作者通知（打赏到账 / 收益看板推送） */
    public function toAuthor(int $authorId, string $type, array $payload): void
    {
        $this->publish(
            "author:{$authorId}:notify",
            ['type' => $type, 'payload' => $payload],
        );
    }

    private function publish(string $channel, array $envelope): void
    {
        try {
            Redis::connection('default')->publish($channel, json_encode($envelope, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            // 降级：前端 5s 轮询兜底（任务 9.4），广播失败不影响主流程
            Log::warning('实时广播失败（已降级轮询）', ['channel' => $channel, 'err' => $e->getMessage()]);
        }
    }
}
