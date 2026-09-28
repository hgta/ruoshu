<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\Payment\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 作者提现（任务 10.8，features.withdrawal flag 控制）：
 * 申请 → 财务人工审核 → 平台打款。flag 关闭时入口 503（合规：先有 SOP 再开钱袋子）。
 */
class WithdrawalController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function index(Request $request)
    {
        return view('author.withdrawals', [
            'withdrawals' => Withdrawal::where('user_id', $request->user()->id)
                ->orderByDesc('id')->paginate(20),
            'withdrawable' => $this->ledger->withdrawableBalance($request->user()->id),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(config('features.withdrawal'), 503, '提现功能即将开放');

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:10000'], // 单笔 ≥¥100
        ]);

        $balance = $this->ledger->withdrawableBalance($request->user()->id);
        abort_if($validated['amount'] > $balance, 422, "可提现余额不足（当前 ¥{$this->yuan($balance)}）");

        $wd = Withdrawal::create([
            'user_id' => $request->user()->id,
            'amount' => $validated['amount'],
            'trade_no' => 'WD'.date('YmdHis').strtoupper(Str::random(8)),
            'status' => Withdrawal::ST_PENDING,
        ]);

        return response()->json(['trade_no' => $wd->trade_no, 'status' => 'pending'], 201);
    }

    private function yuan(int $fen): string
    {
        return number_format($fen / 100, 2);
    }
}
