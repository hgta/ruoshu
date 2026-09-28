<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\MonthlyStatement;
use App\Models\User;
use App\Services\Payment\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * 作者收益看板（任务 10.5）：逐笔明细（毛/抽/净）+ 汇总。
 * 打赏实时推送由 PaymentService::afterSettle → RealtimeBroadcaster::toAuthor 承担（≤5s）。
 */
class FinanceController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function index(Request $request)
    {
        /** @var User $author */
        $author = $request->user();

        // 作者名下所有书的账本明细（旧→新倒序分页）
        $entries = LedgerEntry::whereHas('book', fn ($q) => $q->where('user_id', $author->id))
            ->with('book:id,title')
            ->orderByDesc('id')
            ->paginate(20, page: $request->integer('page', 1));

        // 汇总
        $summary = LedgerEntry::whereHas('book', fn ($q) => $q->where('user_id', $author->id))
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(gross),0) AS gross, COALESCE(SUM(fee),0) AS fee, COALESCE(SUM(net),0) AS net')
            ->first();

        return view('author.finance', [
            'entries' => $entries,
            'summary' => $summary,
            'withdrawable' => $this->ledger->withdrawableBalance($author->id),
            'statements' => MonthlyStatement::where('user_id', $author->id)
                ->orderByDesc('period')->limit(12)->get(),
        ]);
    }

    /** 下载已生成的月度对账单（72h 签名 URL 语义：由下载端点限时授权） */
    public function downloadStatement(MonthlyStatement $statement)
    {
        abort_unless($statement->user_id === auth()->id() && $statement->status === MonthlyStatement::ST_DONE, 404);

        return response()->download(
            Storage::disk(config('services.forensic.disk', 'local'))->path($statement->pdf_path),
        );
    }
}
