<?php

declare(strict_types=1);

namespace App\Services\Reading;

use App\Models\Bookshelf;
use App\Models\Chapter;
use App\Models\ChapterPurchase;
use App\Models\Subscription;
use App\Models\User;

/**
 * 阅读门禁：
 *  - 未登录可读每书前 3 章（免登门槛，任务 5.3）
 *  - VIP 章节：单购 or 有效月卡
 *  - 判定结果影响缓存策略（付费内容禁公共 CDN，per-user 渲染）
 */
class AccessService
{
    public const FREE_PREVIEW_LIMIT = 3;

    public function canRead(?User $user, Chapter $chapter): bool
    {
        if (! $chapter->is_paid) {
            return true;
        }

        // 免费预览：前 3 章即使是 VIP 标志也放行（作者一般不会标，但保底）
        if ($chapter->chapter_no <= self::FREE_PREVIEW_LIMIT) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        // 已单购（幂等 UNIQUE 保证不重复扣费）
        $purchased = ChapterPurchase::where('user_id', $user->id)
            ->where('chapter_id', $chapter->id)
            ->where('status', ChapterPurchase::ST_PAID)
            ->exists();

        if ($purchased) {
            return true;
        }

        // 有效月卡
        return Subscription::where('user_id', $user->id)
            ->where('book_id', $chapter->book_id)
            ->where('status', Subscription::ST_ACTIVE)
            ->where('ends_at', '>', now())
            ->exists();
    }

    /** 门禁失败时给前端下一步提示 */
    public function denialReason(?User $user, Chapter $chapter): string
    {
        if ($user === null) {
            return 'login';   // 前端弹登录（体验红线：登录后 ≤15s 回到原位）
        }

        return 'pay';        // 前端弹解锁面板（单购 ¥X 或开通月卡）
    }

    /** 更新阅读进度（登录用户，书架同步） */
    public function touchProgress(User $user, Chapter $chapter, int $paraNo = 0, int $offset = 0): void
    {
        Bookshelf::updateOrCreate(
            ['user_id' => $user->id, 'book_id' => $chapter->book_id],
            [
                'last_read_chapter_id' => $chapter->id,
                'last_read_para_no' => $paraNo,
                'last_read_offset' => $offset,
                'last_read_at' => now(),
            ],
        );
    }
}
