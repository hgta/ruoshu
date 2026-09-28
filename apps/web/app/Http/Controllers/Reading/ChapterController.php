<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reading;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Chapter;
use App\Services\Reading\AccessService;
use App\Services\Reading\WatermarkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ChapterController extends Controller
{
    public function __construct(
        private readonly AccessService $access,
        private readonly WatermarkService $watermark,
    ) {}

    /** 阅读页：门禁 → 免费走热缓存 / 付费 per-user 水印渲染 → 进度 */
    public function read(Request $request, Chapter $chapter)
    {
        abort_unless($chapter->status === Chapter::STATUS_PUBLISHED, 404);
        // 书已下架/草稿 → 正文同样下线（任务 11.4 读者侧内容下线；存证记录不受影响）
        abort_unless(
            in_array($chapter->book->status, [Book::STATUS_ONGOING, Book::STATUS_FINISHED]),
            404,
        );

        $book = $chapter->book;
        $user = $request->user();

        // 1. 门禁
        if (! $this->access->canRead($user, $chapter)) {
            $reason = $this->access->denialReason($user, $chapter);

            if ($request->expectsJson()) {
                return response()->json([
                    'code' => $reason,
                    'message' => $reason === 'login' ? '请先登录' : '本章为 VIP 章节',
                    'chapter' => ['id' => $chapter->id, 'price' => $chapter->price],
                ], $reason === 'login' ? 401 : 402);
            }

            // 页面请求：登录场景记录 intended（15s 红线的回原位路径）
            if ($reason === 'login') {
                session(['url.intended' => $request->fullUrl()]);
            }

            return view('books.locked', ['chapter' => $chapter, 'book' => $book, 'reason' => $reason]);
        }

        // 2. 正文
        if (! $chapter->is_paid) {
            // 免费章：Redis 热缓存，命中 p95 ≤200ms（任务 5.6）
            $content = Cache::remember(
                "chapter:content:{$chapter->id}",
                now()->addHours(6),
                fn () => $chapter->content?->content ?? '',
            );
        } else {
            // 付费章：per-user 水印渲染，禁共享缓存
            $content = $this->watermark->render($user, $chapter, $chapter->content?->content ?? '');
        }

        $book->increment('read_count');

        // 3. 阅读进度（登录用户）
        if ($user) {
            $this->access->touchProgress($user, $chapter);
        }

        // 4. 目录导航
        $prev = Chapter::where('book_id', $book->id)
            ->where('chapter_no', '<', $chapter->chapter_no)
            ->where('status', Chapter::STATUS_PUBLISHED)
            ->orderByDesc('chapter_no')->first();
        $next = Chapter::where('book_id', $book->id)
            ->where('chapter_no', '>', $chapter->chapter_no)
            ->where('status', Chapter::STATUS_PUBLISHED)
            ->orderBy('chapter_no')->first();

        return view('books.read', [
            'book' => $book,
            'chapter' => $chapter,
            'content' => $this->splitParagraphs($content),
            'authorNote' => $chapter->content?->author_note,
            'prev' => $prev,
            'next' => $next,
        ]);
    }

    /** 正文分段（段落锚点渲染，段评绑定基础） */
    private function splitParagraphs(string $content): array
    {
        $paras = preg_split("/\n\s*\n|\r\n\s*\r\n/u", trim($content));

        return array_values(array_filter(array_map('trim', $paras ?: []), fn ($p) => $p !== ''));
    }
}
