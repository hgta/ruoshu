<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterPurchase;
use App\Models\Donation;
use App\Models\Gift;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Realtime\RealtimeBroadcaster;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 支付服务（任务 10.1/10.3/10.6）：
 * 下单 → 渠道支付 → 结算三段式。结算幂等：重复回调不重复落账（UNIQUE(source_type,source_id) 兜底）。
 *
 * MVP 渠道为本地模拟收银台（pay_trade_no + 模拟回调）；
 * 生产接微信/支付宝官方 SDK 时仅替换 PayChannel 层，结算入口不变。
 *
 * 结算副作用（打赏弹幕广播 / 作者通知）在事务提交后触发（afterCommit），失败不影响账本。
 */
class PaymentService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly RealtimeBroadcaster $broadcaster,
        private readonly SplitChannel $splitChannel,
    ) {}

    /** 打赏下单（礼物 or 自由金额，任务 10.1） */
    public function createDonation(User $user, Book $book, string $channel, ?int $giftId = null, ?int $amount = null, ?int $chapterId = null): Donation
    {
        if ($giftId !== null) {
            $gift = Gift::where('enabled', true)->findOrFail($giftId);
            $amount = (int) $gift->price;
        }

        $amount = (int) $amount;
        if ($amount < (int) config('payment.donation_min')) {
            throw new RuntimeException('打赏金额不能低于 ¥1');
        }

        return Donation::create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'chapter_id' => $chapterId,
            'gift_id' => $giftId,
            'amount' => $amount,
            'pay_channel' => $channel,
            'pay_trade_no' => $this->tradeNo('DON'),
            'status' => Donation::ST_UNPAID,
        ]);
    }

    /** 章节单购下单（幂等：已购直接返回，未付单复用，任务 10.3） */
    public function createPurchase(User $user, Chapter $chapter, string $channel): ChapterPurchase
    {
        return DB::transaction(function () use ($user, $chapter, $channel) {
            if ($existing = $user->purchases()->where('chapter_id', $chapter->id)->lockForUpdate()->first()) {
                return $existing;
            }

            return ChapterPurchase::create([
                'user_id' => $user->id,
                'book_id' => $chapter->book_id,
                'chapter_id' => $chapter->id,
                'price' => (int) $chapter->price,
                'pay_channel' => $channel,
                'pay_trade_no' => $this->tradeNo('CHP'),
                'status' => ChapterPurchase::ST_UNPAID,
            ]);
        });
    }

    /** 月卡订阅下单（任务 10.3） */
    public function createSubscription(User $user, Book $book, string $channel): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'price' => (int) config('payment.subscription_price'),
            'pay_channel' => $channel,
            'pay_trade_no' => $this->tradeNo('SUB'),
            'status' => Subscription::ST_UNPAID,
        ]);
    }

    /**
     * 结算（渠道回调统一入口）：同事务 更新来源状态 + 落账。
     * 幂等：已结算来源直接返回 false。
     *
     * @return bool 本次是否实际结算（false = 重复回调）
     */
    public function settle(string $tradeNo): bool
    {
        $settled = DB::transaction(function () use ($tradeNo) {
            $source = $this->findByTradeNo($tradeNo);

            if ($source instanceof Donation) {
                if ($source->status === Donation::ST_PAID) {
                    return null;
                }
                $source->update(['status' => Donation::ST_PAID, 'paid_at' => now()]);
            } elseif ($source instanceof ChapterPurchase) {
                if ($source->status === ChapterPurchase::ST_PAID) {
                    return null;
                }
                $source->update(['status' => ChapterPurchase::ST_PAID, 'paid_at' => now()]);
            } elseif ($source instanceof Subscription) {
                if ($source->status === Subscription::ST_ACTIVE) {
                    return null;
                }
                $source->update([
                    'status' => Subscription::ST_ACTIVE,
                    'starts_at' => now(),
                    'ends_at' => now()->addDays(30),
                    'paid_at' => now(),
                ]);
            } else {
                throw new RuntimeException("交易号不存在: {$tradeNo}");
            }

            return ['source' => $source, 'entry' => $this->ledger->record($source)];
        });

        if ($settled === null) {
            return false; // 重复回调：幂等 no-op
        }

        // 收银台轮询确认（6 分钟足够覆盖支付窗口）
        Cache::put("pay:status:{$tradeNo}", true, now()->addMinutes(6));

        // 分账意图登记（任务 10.2：flag 关闭时挂平台托管，开启后走真实分账）
        $this->splitChannel->register($settled['source'], $settled['entry']);

        // 副作用：广播/通知（事务已提交，尽力而为）
        $this->afterSettle($settled['source']);

        return true;
    }

    /** 打赏成功 → 章节弹幕特效广播 ≤3s + 作者即时通知 ≤5s（任务 10.5/10.6） */
    private function afterSettle(Donation|ChapterPurchase|Subscription $source): void
    {
        $book = Book::find($source->book_id);
        if (! $book) {
            return;
        }
        $supporter = User::find($source->user_id)?->name ?? '书友';

        if ($source instanceof Donation) {
            $gift = Gift::find($source->gift_id);
            if ($source->chapter_id) {
                // 章节内打赏特效：同章读者实时可见
                $this->broadcaster->toChapter($book->id, $source->chapter_id, 'donation', [
                    'user' => $supporter,
                    'gift' => $gift?->name,
                    'amount' => $source->amount,
                ]);
            }
            // 作者到账通知（工作台 WS 订阅 author:{id}:notify）
            $this->broadcaster->toAuthor($book->user_id, 'donation_paid', [
                'book' => $book->title,
                'user' => $supporter,
                'gift' => $gift?->name,
                'amount' => $source->amount,
                'at' => now()->toIso8601String(),
            ]);
        }
    }

    /** @return Donation|ChapterPurchase|Subscription|null */
    private function findByTradeNo(string $tradeNo): ?object
    {
        return Donation::where('pay_trade_no', $tradeNo)->first()
            ?? ChapterPurchase::where('pay_trade_no', $tradeNo)->first()
            ?? Subscription::where('pay_trade_no', $tradeNo)->first();
    }

    private function tradeNo(string $prefix): string
    {
        return $prefix.date('YmdHis').strtoupper(Str::random(10));
    }
}
