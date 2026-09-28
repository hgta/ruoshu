<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Book;
use App\Models\ModerationReport;
use App\Models\Withdrawal;
use Illuminate\Http\Request;

/**
 * 管理后台首页 + 审计日志（任务 12.1/12.6）。
 * 部署约定：生产挂 admin 独立子域（网关白名单），应用层 EnsureAdminAccess 双保险。
 */
class DashboardController extends Controller
{
    public function index()
    {
        return view('admin.dashboard', [
            'pendingReports' => ModerationReport::where('status', ModerationReport::ST_PENDING)->count(),
            'pendingWithdrawals' => Withdrawal::where('status', Withdrawal::ST_PENDING)->count(),
            'offlineBooks' => Book::where('status', Book::STATUS_OFFLINE)->count(),
            'recentAudits' => AuditLog::latest('id')->limit(30)->get(),
        ]);
    }

    /** 审计日志全量（append-only；含签名校验状态） */
    public function audits(Request $request)
    {
        $logs = AuditLog::with('actor:id,name')
            ->orderByDesc('id')
            ->paginate(50, page: $request->integer('page', 1));

        return view('admin.audits', ['logs' => $logs]);
    }
}
