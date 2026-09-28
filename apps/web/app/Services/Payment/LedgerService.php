<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\ChapterPurchase;
use App\Models\Donation;
use App\Models\LedgerEntry;
use App\Models\Subscription;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;

/**
 * 账本服务（任务 10.4）：唯一真源。
 * 所有金钱流动（打赏/单购/月卡）与来源记录同事务落账，链式 entry_hash 防篡改。
 *
 * 链式哈希：SHA-256(本条关键字段 + prev_hash)，prev_hash 取当前尾链；
 * 尾链用 SELECT ... FOR UPDATE 锁定，防并发写入导致断链。
 */
class LedgerService
{
    /**
     * 与来源记录同事务落账。调用方必须已在 DB::transaction 内。
     *
     * @param  Donation|ChapterPurchase|Subscription  $source
     */
    public function record(object $source): LedgerEntry
    {
        [$sourceType, $sourceId, $userId, $bookId, $chapterId, $gross] = $this->sourceMeta($source);
        $feeRate = (float) config("payment.fee_rate.{$sourceType}");
        $fee = (int) round($gross * $feeRate);
        $net = $gross - $fee;

        // 尾链锁定（FOR UPDATE 串行化并发结算；SQLite 测试库自动忽略锁子句）
        $prevHash = LedgerEntry::orderByDesc('id')->lockForUpdate()->value('entry_hash');

        return LedgerEntry::create([
            'user_id' => $userId,
            'book_id' => $bookId,
            'chapter_id' => $chapterId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'gross' => $gross,
            'fee_rate' => $feeRate,
            'fee' => $fee,
            'net' => $net,
            'entry_hash' => LedgerEntry::computeHash($userId, $bookId, $sourceType, $sourceId, $gross, $fee, $net, $prevHash),
            'prev_hash' => $prevHash,
        ]);
    }

    /** 作者可提现余额：净得累计 - 已提现 - 审核中 */
    public function withdrawableBalance(int $authorId): int
    {
        $earned = (int) LedgerEntry::whereHas('book', fn ($q) => $q->where('user_id', $authorId))
            ->sum('net');

        $locked = (int) Withdrawal::where('user_id', $authorId)
            ->whereIn('status', [Withdrawal::ST_PENDING, Withdrawal::ST_APPROVED, Withdrawal::ST_PAID])
            ->sum('amount');

        return max(0, $earned - $locked);
    }

    /** @return array{string,int,int,int,?int,int} [source_type, source_id, user_id, book_id, chapter_id, gross] */
    private function sourceMeta(object $s): array
    {
        if ($s instanceof Donation) {
            return [LedgerEntry::SOURCE_DONATION, $s->id, $s->user_id, $s->book_id, $s->chapter_id, (int) $s->amount];
        }
        if ($s instanceof ChapterPurchase) {
            return [LedgerEntry::SOURCE_PURCHASE, $s->id, $s->user_id, $s->book_id, $s->chapter_id, (int) $s->price];
        }
        if ($s instanceof Subscription) {
            return [LedgerEntry::SOURCE_SUBSCRIPTION, $s->id, $s->user_id, $s->book_id, null, (int) $s->price];
        }

        throw new \InvalidArgumentException('不支持的账本来源: '.get_class($s));
    }
}
