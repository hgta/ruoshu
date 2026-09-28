<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reading;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\EvidenceRecord;
use App\Models\Tag;
use Illuminate\Http\Request;

class BookController extends Controller
{
    /** 作品详情页：封面/标签/统计/存证徽章/最新章节/书评区 */
    public function show(Request $request, Book $book)
    {
        abort_unless(in_array($book->status, [Book::STATUS_ONGOING, Book::STATUS_FINISHED]), 404);

        $book->load(['tags', 'author.authorProfile'])
            ->loadCount('chapters');

        // 存证徽章（最新已确认记录）
        $evidence = $book->evidenceRecords()
            ->where('status', EvidenceRecord::ST_CONFIRMED)
            ->latest('confirmed_at')
            ->first();

        $chapters = $book->chapters()
            ->where('status', Chapter::STATUS_PUBLISHED)
            ->orderBy('chapter_no')
            ->limit(200)
            ->get(['id', 'chapter_no', 'title', 'is_paid', 'price', 'word_count', 'published_at']);

        $reviews = Comment::where('book_id', $book->id)
            ->where('target_type', Comment::TARGET_BOOK)
            ->where('status', Comment::ST_NORMAL)
            ->with('user:id,name,avatar')
            ->latest()
            ->limit(20)
            ->get();

        $book->increment('view_count');

        return view('books.show', compact('book', 'evidence', 'chapters', 'reviews'));
    }

    /** 分类浏览（任务 8.1：6 大类 + 标签组合筛选 + 状态/排序） */
    public function explore(Request $request)
    {
        $categoryId = (int) $request->query('category', 0);
        $tagId = (int) $request->query('tag', 0);
        $status = $request->query('status', '');
        $sort = $request->query('sort', 'new');

        $books = Book::visible()
            ->when($categoryId > 0, fn ($q) => $q->where('category_id', $categoryId))
            ->when($status === 'finished', fn ($q) => $q->where('status', Book::STATUS_FINISHED))
            ->when($tagId > 0, fn ($q) => $q->whereHas('tags', fn ($t) => $t->where('tags.id', $tagId)))
            ->when($sort === 'hot', fn ($q) => $q->orderByDesc('view_count'))
            ->when($sort === 'collect', fn ($q) => $q->orderByDesc('collect_count'))
            ->when($sort === 'words', fn ($q) => $q->orderByDesc('word_count'))
            ->unless(in_array($sort, ['hot', 'collect', 'words']), fn ($q) => $q->orderByDesc('created_at'))
            ->with('author:id,name,avatar')
            ->paginate(24)
            ->withQueryString();

        // 标签侧栏：分类下的二级标签 + 全站热度标签
        $hotTags = Tag::orderByDesc('use_count')->limit(20)->get();

        return view('books.explore', [
            'books' => $books,
            'categories' => config('categories'),
            'currentCategory' => $categoryId,
            'currentTag' => $tagId,
            'currentStatus' => $status,
            'currentSort' => $sort,
            'hotTags' => $hotTags,
        ]);
    }
}
