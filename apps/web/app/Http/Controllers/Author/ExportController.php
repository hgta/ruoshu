<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Http\Controllers\Controller;
use App\Jobs\ExportBookJob;
use App\Models\Book;
use App\Models\Export;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * 作品一键导出（任务 11.2）：作者主权落地——作品与证据随时可携带离场。
 * Markdown / TXT ZIP + manifest（含存证 merkle root），异步生成，72h 内可下载。
 */
class ExportController extends Controller
{
    public function index(Request $request)
    {
        return view('author.exports', [
            'exports' => Export::where('user_id', $request->user()->id)
                ->whereIn('format', ['zip-md', 'zip-txt'])
                ->orderByDesc('id')
                ->paginate(20),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'book_id' => ['required', 'integer'],
            'format' => ['required', 'in:zip-md,zip-txt'],
        ]);

        $book = Book::findOrFail($validated['book_id']);
        $this->authorize('update', $book);

        ExportBookJob::dispatch($request->user()->id, $book->id, $validated['format']);

        return back()->with('status', '导出任务已排队（完成后可下载，有效期 72 小时）');
    }

    /** 下载（鉴权 + 过期即 410，等效 OSS 签名 URL 语义） */
    public function download(Request $request, Export $export)
    {
        abort_unless($export->user_id === $request->user()->id, 403);
        abort_unless($export->status === Export::ST_DONE, 404, '导出尚未完成');
        abort_unless($export->expires_at && $export->expires_at->isFuture(), 410, '下载链接已过期');

        $disk = Storage::disk(config('services.forensic.disk', 'local'));
        abort_unless($disk->exists($export->oss_key), 404, '文件不存在');

        return $disk->download($export->oss_key, "ruoshu-export-{$export->book_id}.zip");
    }
}
