<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\WatermarkAudit;
use App\Models\WatermarkSeed;
use App\Services\Reading\WatermarkService;
use Illuminate\Http\Request;

/**
 * 作者水印预览与披露页（任务 7.3）：
 * 作者看到的读者同款版本——以作者自己的 user_id 渲染带水印正文，
 * 直观披露"付费章节会按读者身份嵌入不可见水印"这一事实（透明原则）。
 */
class WatermarkController extends Controller
{
    public function __construct(private readonly WatermarkService $watermark) {}

    public function show(Request $request, Book $book)
    {
        $this->authorize('view', $book);

        $previewChapter = $book->chapters()
            ->where('status', Chapter::STATUS_PUBLISHED)
            ->where('is_paid', true)
            ->orderBy('chapter_no')
            ->first();

        // 以作者本人身份渲染（读者同款路径，同一 WatermarkService）
        $previewContent = null;
        if ($previewChapter && $previewChapter->content) {
            $previewContent = $this->watermark->render(
                $request->user(),
                $previewChapter,
                $previewChapter->content->content ?? '',
            );
        }

        return view('author.watermark', [
            'book' => $book,
            'chapter' => $previewChapter,
            'previewContent' => $previewContent,
            'seedCount' => WatermarkSeed::where('book_id', $book->id)->count(),
            'traceCount' => WatermarkAudit::where('book_id', $book->id)->count(),
        ]);
    }
}
