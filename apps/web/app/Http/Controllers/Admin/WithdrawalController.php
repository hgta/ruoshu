<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateMonthlyStatementJob;
use App\Models\Book;
use App\Models\Withdrawal;
use App\Services\AuditLogger;
use App\Services\Payment\LedgerVerifier;
use Illuminate\Http\Request;

/**
 * 财务审核入口（任务 10.8 审核侧 + 10.4 对账单触发）：
 * 所有动作 append-only 留痕 audit_logs（操作人 + 前后状态）。
 * 提现打款为线下人工执行，系统只管状态与账实对齐。
 */
class WithdrawalController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.withdrawals', [
            'withdrawals' => Withdrawal::with('user:id,name')
                ->orderBy('status')->orderByDesc('id')->paginate(30),
        ]);
    }

    /** 通过（进入待打款） */
    public function approve(Request $request, Withdrawal $withdrawal)
    {
        abort_unless($withdrawal->status === Withdrawal::ST_PENDING, 409, '该申请不在待审核状态');

        $withdrawal->update(['status' => Withdrawal::ST_APPROVED]);

        AuditLogger::log(
            $request->user(), 'withdrawal.approve', 'withdrawal', $withdrawal->id,
            ['status' => Withdrawal::ST_PENDING], ['status' => Withdrawal::ST_APPROVED],
            '财务审核通过，待打款',
        );

        return back()->with('ok', '已通过，等待打款');
    }

    /** 驳回（必须备注理由） */
    public function reject(Request $request, Withdrawal $withdrawal)
    {
        abort_unless($withdrawal->status === Withdrawal::ST_PENDING, 409, '该申请不在待审核状态');

        $validated = $request->validate(['note' => ['required', 'string', 'max:500']]);

        $withdrawal->update([
            'status' => Withdrawal::ST_REJECTED,
            'review_note' => $validated['note'],
            'reviewer_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        AuditLogger::log(
            $request->user(), 'withdrawal.reject', 'withdrawal', $withdrawal->id,
            ['status' => Withdrawal::ST_PENDING], ['status' => Withdrawal::ST_REJECTED],
            $validated['note'],
        );

        return back()->with('ok', '已驳回');
    }

    /** 确认打款完成（线下打款后回填） */
    public function markPaid(Request $request, Withdrawal $withdrawal)
    {
        abort_unless($withdrawal->status === Withdrawal::ST_APPROVED, 409, '仅"已通过待打款"可标记打款');

        $withdrawal->update([
            'status' => Withdrawal::ST_PAID,
            'paid_at' => now(),
            'reviewer_id' => $request->user()->id,
        ]);

        AuditLogger::log(
            $request->user(), 'withdrawal.paid', 'withdrawal', $withdrawal->id,
            ['status' => Withdrawal::ST_APPROVED], ['status' => Withdrawal::ST_PAID],
            '线下打款完成回填',
        );

        return back()->with('ok', '已标记打款完成');
    }

    /** 触发月度对账单生成（任务 10.7 入口，也由调度器月初自动触发） */
    public function generateStatements()
    {
        $period = now()->subMonth()->format('Y-m');
        $authors = Book::where('status', 1)
            ->distinct()->pluck('user_id');

        $authors->each(fn ($authorId) => GenerateMonthlyStatementJob::dispatch($authorId, $period));

        return back()->with('ok', "已为 {$authors->count()} 位作者排期生成 {$period} 对账单");
    }

    /** 账本校验（任务 10.7：检测链断裂） */
    public function verifyLedger()
    {
        $report = app(LedgerVerifier::class)->verify();

        return view('admin.ledger-verify', ['report' => $report]);
    }
}
