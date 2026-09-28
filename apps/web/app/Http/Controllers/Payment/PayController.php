<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ChapterPurchase;
use App\Models\Gift;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 支付入口（任务 10.1/10.3）：打赏 / 单购 / 月卡。
 * FeatureFlagGate 已按 pay/* 与 donate* 路由做 payment flag 硬闸（503）。
 *
 * 渠道说明：MVP 为本地模拟收银台（GET /pay/{trade_no} 页面 → 模拟回调）；
 * 生产接微信/支付宝官方 SDK 后，回调改为验签 + 金额核对，结算入口 PaymentService::settle 不变。
 */
class PayController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /** 礼物列表（阅读页打赏面板，公开只读） */
    public function gifts(): JsonResponse
    {
        return response()->json(
            Gift::where('enabled', true)->orderBy('sort')->get(['id', 'name', 'icon', 'price']),
        );
    }

    /** 打赏下单（任务 10.1）：礼物 or 自由金额 */
    public function donate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'book_id' => ['required', 'integer'],
            'chapter_id' => ['nullable', 'integer'],
            'gift_id' => ['nullable', 'integer'],
            'amount' => ['nullable', 'integer', 'min:100'], // 分
            'channel' => ['required', 'in:wechat,alipay'],
        ]);

        $book = Book::findOrFail($validated['book_id']);

        $donation = $this->payments->createDonation(
            $request->user(),
            $book,
            $validated['channel'],
            $validated['gift_id'] ?? null,
            $validated['amount'] ?? null,
            $validated['chapter_id'] ?? null,
        );

        return response()->json(['trade_no' => $donation->pay_trade_no], 201);
    }

    /** 章节单购下单（任务 10.3，幂等） */
    public function purchase(Request $request, Chapter $chapter): JsonResponse
    {
        abort_unless($chapter->is_paid, 400, '免费章节无需购买');
        abort_unless($chapter->status === Chapter::STATUS_PUBLISHED, 404);

        $validated = $request->validate(['channel' => ['required', 'in:wechat,alipay']]);

        $purchase = $this->payments->createPurchase($request->user(), $chapter, $validated['channel']);

        // 幂等：已购直接确认成功
        if ($purchase->status === ChapterPurchase::ST_PAID) {
            return response()->json(['trade_no' => $purchase->pay_trade_no, 'paid' => true]);
        }

        return response()->json(['trade_no' => $purchase->pay_trade_no, 'paid' => false], 201);
    }

    /** 月卡订阅下单（任务 10.3） */
    public function subscribe(Request $request, Book $book): JsonResponse
    {
        $validated = $request->validate(['channel' => ['required', 'in:wechat,alipay']]);

        $sub = $this->payments->createSubscription($request->user(), $book, $validated['channel']);

        return response()->json(['trade_no' => $sub->pay_trade_no], 201);
    }

    /** 模拟收银台页（生产替换为微信/支付宝 H5 拉起页） */
    public function cashier(string $tradeNo)
    {
        return view('payment.cashier', ['tradeNo' => $tradeNo]);
    }

    /**
     * 支付回调（模拟渠道）。生产必改：
     *  1. 微信/支付宝官方回调验签（平台证书）
     *  2. 回调金额与订单金额核对
     *  3. 返回渠道要求的 ACK 报文
     * 结算入口幂等，重放安全。
     */
    public function callback(Request $request, string $tradeNo): JsonResponse
    {
        $settled = $this->payments->settle($tradeNo);

        return response()->json([
            'code' => 0,
            'settled' => $settled, // false = 重复回调（幂等 no-op）
        ]);
    }

    /** 前端轮询支付结果（收银台页确认用） */
    public function status(string $tradeNo): JsonResponse
    {
        $key = "pay:status:{$tradeNo}";
        $paid = (bool) Cache::get($key, false);

        return response()->json(['paid' => $paid]);
    }
}
