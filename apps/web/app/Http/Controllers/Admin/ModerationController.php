<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncBookToSearchIndexJob;
use App\Models\Book;
use App\Models\Comment;
use App\Models\Danmu;
use App\Models\Export;
use App\Models\ModerationReport;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/**
 * 审核队列（任务 12.2/12.3）：举报/敏感词命中/DMCA。
 * 处理三原则：
 *   1. 处理动作必附强制备注（handle_note 表级非空）
 *   2. DMCA：先看证据（存证记录 + 作者已生成的取证包）→ 下架 / 转法务，绝不无故删内容
 *   3. 全量 audit_logs 签名留痕
 */
class ModerationController extends Controller
{
    /** 队列：待处理举报（DMCA 优先） */
    public function index(Request $request)
    {
        $reports = ModerationReport::with('reporter:id,name')
            ->where('status', ModerationReport::ST_PENDING)
            ->orderByRaw('CASE WHEN reason_kind = '.ModerationReport::KIND_DMCA.' THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->paginate(30, page: $request->integer('page', 1));

        return view('admin.moderation', [
            'reports' => $reports,
            'targets' => $this->targetsOf($reports->getCollection()),
        ]);
    }

    /** DMCA/侵权详情：加载证据面板（至信链记录 + 取证包 + 正文比对线索） */
    public function show(ModerationReport $report)
    {
        abort_unless($report->status === ModerationReport::ST_PENDING, 404);

        $book = $report->target_type === ModerationReport::TARGET_BOOK
            ? Book::find($report->target_id)
            : null;

        $evidence = $book ? $book->evidenceRecords()->latest('id')->limit(10)->get() : collect();

        $forensicPackages = $book
            ? Export::where('book_id', $book->id)->where('format', 'pdf')->latest()->limit(5)->get()
            : collect();

        return view('admin.dmca', [
            'report' => $report->load('reporter:id,name'),
            'book' => $book,
            // 至信链核验：本地状态 + tx_id（生产接至信链在线查询 API 复核）
            'evidence' => $evidence,
            'packages' => $forensicPackages,
        ]);
    }

    /**
     * 处理动作（强制备注）：
     *   hide → 隐藏评论/弹幕；offline → 书下架（存证保留）；legal → 转法务；none → 忽略
     */
    public function handle(Request $request, ModerationReport $report)
    {
        $validated = $request->validate([
            'action' => ['required', 'in:hide,offline,legal,none'],
            'note' => ['required', 'string', 'min:5', 'max:500'], // 强制备注
        ]);

        abort_unless($report->status === ModerationReport::ST_PENDING, 409, '该举报已处理');

        // 执行动作
        switch ($validated['action']) {
            case 'hide':
                $this->hideTarget($report);
                break;
            case 'offline':
                $book = Book::find($report->target_id)
                    ?? $this->bookOfComment($report);
                abort_if($book === null, 422, '无法定位作品');
                $book->update(['status' => Book::STATUS_OFFLINE]);
                SyncBookToSearchIndexJob::dispatch($book->id);
                break;
            case 'legal':
                // 转法务：站内下线 + 移交（任务 12.3 流程：取证包 + 存证记录随工单移交）
                $book = $this->bookOfComment($report) ?? Book::find($report->target_id);
                $book?->update(['status' => Book::STATUS_OFFLINE]);
                if ($book) {
                    SyncBookToSearchIndexJob::dispatch($book->id);
                }
                break;
            case 'none':
                break; // 忽略（备注说明理由）
        }

        $report->update([
            'status' => $validated['action'] === 'none'
                ? ModerationReport::ST_DISMISSED
                : ModerationReport::ST_HANDLED,
            'handler_id' => $request->user()->id,
            'action' => $validated['action'],
            'handle_note' => $validated['note'],
            'handled_at' => now(),
        ]);

        AuditLogger::log(
            $request->user(),
            "moderation.{$validated['action']}",
            $report->target_type,
            $report->target_id,
            ['status' => ModerationReport::ST_PENDING],
            ['status' => $report->status],
            $validated['note'],
        );

        return redirect()->route('admin.moderation')->with('ok', '已处理（全量留痕）');
    }

    /** 举报评论的定位到作品（下架 DMCA 时需要） */
    private function bookOfComment(ModerationReport $report): ?Book
    {
        if ($report->target_type === ModerationReport::TARGET_COMMENT) {
            $comment = Comment::find($report->target_id);

            return $comment?->book;
        }

        return null;
    }

    private function hideTarget(ModerationReport $report): void
    {
        if ($report->target_type === ModerationReport::TARGET_COMMENT) {
            Comment::whereKey($report->target_id)->update(['status' => Comment::ST_HIDDEN]);

            return;
        }
        if ($report->target_type === ModerationReport::TARGET_DANMU) {
            Danmu::whereKey($report->target_id)->update(['status' => Danmu::ST_HIDDEN]);

            return;
        }
        abort(422, 'hide 仅适用于评论/弹幕');
    }

    /** @return array<int, string> target_id → 展示文本 */
    private function targetsOf($reports): array
    {
        $map = [];
        foreach ($reports as $r) {
            $map[$r->id] = match ($r->target_type) {
                ModerationReport::TARGET_COMMENT => Comment::find($r->target_id)?->content ?? '（评论已删除）',
                ModerationReport::TARGET_DANMU => Danmu::find($r->target_id)?->content ?? '（弹幕已删除）',
                ModerationReport::TARGET_BOOK => Book::find($r->target_id)?->title ?? '（作品已删除）',
                default => '未知目标',
            };
        }

        return $map;
    }
}
