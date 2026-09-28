<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\LedgerEntry;
use Illuminate\Support\Facades\Log;

/**
 * 分账通道（任务 10.2）：微信/支付宝官方分账的接入位。
 *
 * 合规红线：作者个体户资质与分账协议（法务材料）就绪前，features.profit_share 必须为 false——
 * 此时净得仅记账（平台托管），不触发任何真实资金流动，规避二清风险。
 * flag 开启后替换本实现为官方 SDK 调用（微信 profitsharing / 支付宝分账 API）。
 */
class SplitChannel
{
    /**
     * 结算成功后登记分账意图。
     * MVP：flag 关闭 → 只记日志（款项挂平台托管账户，提现走 10.8 人工审核打款）；
     * flag 开启 → 调官方分账 API（接口预留，未接入）。
     */
    public function register(object $source, LedgerEntry $entry): void
    {
        if (! config('features.profit_share')) {
            Log::debug('分账未开启：净得挂平台托管', [
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'net' => $entry->net,
                'author_id' => $entry->book?->user_id,
            ]);

            return;
        }

        // TODO: 微信/支付宝官方分账 API（个体户资质就绪后接入）
        // wechat: POST /v3/profitsharing/orders  alipay: alipay.trade.order.settle
        Log::info('分账通道已开启但驱动未接入（预留接口）', ['entry_id' => $entry->id]);
    }
}
