<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateForensicPackageJob;
use App\Models\Export;
use App\Models\WatermarkAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * 取证包管理（任务 7.5 收尾）：
 * 生成（异步）→ 列表（站内通知送达）→ 下载（72h 签名 URL 等效：过期即拒）。
 */
class ForensicController extends Controller
{
    /** 从溯源结果发起取证包生成 */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'audit_id' => ['required', 'integer', 'exists:watermark_audits,id'],
            'pirate_source' => ['nullable', 'string', 'max:500', 'url'],
        ]);

        $audit = WatermarkAudit::findOrFail($validated['audit_id']);
        $this->authorize('view', $audit->book);

        GenerateForensicPackageJob::dispatch(
            (int) $request->user()->id,
            $audit->id,
            $validated['pirate_source'] ?? null,
        );

        return redirect()
            ->route('author.forensics')
            ->with('status', '取证包生成中（通常 1 分钟内完成，完成后可在此下载，有效期 72 小时）');
    }

    /** 列表（站内通知送达：完成状态即通知） */
    public function index(Request $request)
    {
        $exports = Export::where('user_id', $request->user()->id)
            ->where('format', 'pdf')
            ->orderByDesc('id')
            ->paginate(20);

        return view('author.forensics', [
            'exports' => $exports,
            'audits' => WatermarkAudit::whereHas('book', fn ($q) => $q->where('user_id', $request->user()->id))
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
        ]);
    }

    /** 下载（鉴权 + 过期校验，等效 OSS 签名 URL 语义） */
    public function download(Request $request, Export $export)
    {
        abort_unless($export->user_id === $request->user()->id, 403);
        abort_unless($export->status === Export::ST_DONE, 404, '取证包尚未生成完成');
        abort_unless($export->expires_at && $export->expires_at->isFuture(), 410, '下载链接已过期');

        $disk = Storage::disk(config('services.forensic.disk', 'local'));
        abort_unless($disk->exists($export->oss_key), 404, '文件不存在');

        return $disk->download(
            $export->oss_key,
            "若书取证报告-{$export->book_id}-{$export->id}.pdf",
        );
    }
}
