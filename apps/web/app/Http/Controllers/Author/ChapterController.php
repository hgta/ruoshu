<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Http\Controllers\Controller;
use App\Jobs\FingerprintChapterJob;
use App\Jobs\PlanWatermarkJob;
use App\Models\Book;
use App\Models\Chapter;
use App\Services\SensitiveWordFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChapterController extends Controller
{
    /** 章节管理首页（目录） */
    public function index(Request $request, Book $book)
    {
        $this->authorize('view', $book);

        return view('author.chapters.index', [
            'book' => $book,
            'chapters' => $book->chapters()->orderBy('chapter_no')->paginate(50),
        ]);
    }

    /** 编辑器（新建草稿 / 编辑已有） */
    public function edit(Request $request, Book $book, ?Chapter $chapter = null)
    {
        $this->authorize('view', $book);
        abort_unless($chapter === null || $chapter->book_id === $book->id, 404);

        return view('author.chapters.editor', [
            'book' => $book,
            'chapter' => $chapter,
            'content' => $chapter?->content?->content ?? '',
        ]);
    }

    /** 自动保存草稿（前端每 10s 调用） */
    public function autosave(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $validated = $request->validate([
            'chapter_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:128'],
            'content' => ['required', 'string', 'max:300000'], // ≤10 万字（utf8mb4 裕量）
        ]);

        $chapter = isset($validated['chapter_id'])
            ? Chapter::where('book_id', $book->id)->findOrFail($validated['chapter_id'])
            : null;

        DB::transaction(function () use ($book, $validated, &$chapter) {
            $wordCount = mb_strlen(preg_replace('/\s/u', '', $validated['content']));

            if (! $chapter) {
                $chapter = Chapter::create([
                    'book_id' => $book->id,
                    'chapter_no' => $book->chapters()->max('chapter_no') + 1,
                    'title' => $validated['title'],
                    'word_count' => $wordCount,
                    'status' => Chapter::STATUS_DRAFT,
                ]);
            } else {
                $chapter->update(['title' => $validated['title'], 'word_count' => $wordCount]);
            }

            $chapter->content()->updateOrCreate(
                ['chapter_id' => $chapter->id],
                ['content' => $validated['content']],
            );
        });

        return response()->json(['chapter_id' => $chapter->id, 'saved_at' => now()->toIso8601String()]);
    }

    /**
     * 发布：敏感词检查 → 落库 → 触发异步流水线（指纹/存证/水印规划）。
     * 发布绝不阻塞（存证无感红线）。
     */
    public function publish(Request $request, Book $book, Chapter $chapter): RedirectResponse
    {
        $this->authorize('update', $book);
        abort_unless($chapter->book_id === $book->id, 404);

        $validated = $request->validate([
            'is_paid' => ['sometimes', 'boolean'],
            'price' => ['sometimes', 'integer', 'between:10,50'], // 分，0.1-0.5 元
            'author_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $content = $chapter->content?->content
            ?? throw new \RuntimeException('章节内容为空');

        // 1. 敏感词（发布侧拦截 → 人工审核队列，任务 12.2）
        $hit = app(SensitiveWordFilter::class)->firstHit($content);
        if ($hit !== null) {
            return back()->with('error', "发布被拦截：包含敏感词「{$hit}」，请修改后重试或等待人工审核");
        }

        // 2. 单章字数上限
        if (mb_strlen($content) > 100000) {
            return back()->with('error', '单章不能超过 10 万字');
        }

        DB::transaction(function () use ($chapter, $book, $validated) {
            $chapter->update([
                'status' => Chapter::STATUS_PUBLISHED,
                'is_paid' => $validated['is_paid'] ?? false,
                'price' => $validated['price'] ?? 20,
                'published_at' => now(),
            ]);
            if ($chapter->is_paid) {
                $book->update(['is_paid' => true]);
            }
            if (! empty($validated['author_note'])) {
                $chapter->content->update(['author_note' => $validated['author_note']]);
            }
            $book->increment('chapter_count');
            $book->increment('word_count', $chapter->word_count);
        });

        // 3. 异步流水线：指纹+存证 → 水印锚点规划（队列不阻塞发布）
        FingerprintChapterJob::dispatch($chapter->id, $content, $chapter->word_count);
        PlanWatermarkJob::dispatch($chapter->id, $content);

        return redirect("/author/books/{$book->id}/chapters")
            ->with('status', '已发布，存证进行中（完成后作品页将显示存证徽章）');
    }

    /** 章节修订：新版本指纹快照，旧版本证据保持可验证 */
    public function revise(Request $request, Book $book, Chapter $chapter): RedirectResponse
    {
        $this->authorize('update', $book);
        abort_unless($chapter->book_id === $book->id, 404);

        $content = $chapter->content?->content ?? '';
        if (mb_strlen($content) < 1) {
            return back()->with('error', '无内容可修订');
        }

        $chapter->increment('version');
        $chapter->update(['status' => Chapter::STATUS_PUBLISHED]);

        // 重新走指纹（新 version 快照，历史 version 不动）
        FingerprintChapterJob::dispatch($chapter->id, $content, $chapter->word_count);

        return back()->with('status', "修订版 v{$chapter->version} 已发布，指纹快照重建中");
    }
}
