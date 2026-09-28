<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Http\Controllers\Controller;
use App\Jobs\SyncBookToSearchIndexJob;
use App\Models\Book;
use App\Models\LicenseChange;
use App\Models\Tag;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BookController extends Controller
{
    /** 作者作品列表 */
    public function index(Request $request)
    {
        return view('author.books.index', [
            'books' => Book::where('user_id', $request->user()->id)
                ->orderByDesc('updated_at')
                ->paginate(20),
        ]);
    }

    /** 新建作品页：6 大类 + 标签 + 5 档授权（默认推荐 CC BY-NC-ND） */
    public function create()
    {
        return view('author.books.create', [
            'categories' => config('categories'),
            'licenseOptions' => Book::LICENSE_MAP,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:64'],
            'intro' => ['required', 'string', 'max:500'],
            'category_id' => ['required', 'integer', 'between:1,6'],
            'license' => ['required', 'integer', 'between:1,5'],
            'cover' => ['nullable', 'image', 'max:2048'],
            'tags' => ['required', 'array', 'min:3', 'max:8'],
            'tags.*' => ['string', 'max:16'],
        ]);

        $user = $request->user();

        $book = Book::create([
            'user_id' => $user->id,
            'title' => $validated['title'],
            'title_hash' => hash('sha256', mb_trim($validated['title'])),
            'intro' => $validated['intro'],
            'category_id' => $validated['category_id'],
            'license' => $validated['license'],
            'status' => Book::STATUS_ONGOING,
            'cover' => $this->storeCover($request),
        ]);

        // 标签（自动创建不存在的）
        foreach ($validated['tags'] as $name) {
            $tag = Tag::firstOrCreate(['name' => $name]);
            $book->tags()->attach($tag->id);
            $tag->increment('use_count');
        }

        // 授权选择记录（链式哈希，任务 11.1）
        LicenseChange::create([
            'book_id' => $book->id,
            'from_license' => 0,
            'to_license' => $validated['license'],
            'change_hash' => hash('sha256', $book->id.'|0|'.$validated['license'].'|'.now()->timestamp),
        ]);

        // 搜索索引同步（任务 8.2：队列异步 ≤30s 生效）
        SyncBookToSearchIndexJob::dispatch($book->id);

        return redirect("/author/books/{$book->id}/chapters")->with('status', '作品已创建，开始写第一章吧');
    }

    /** 作品设置：授权变更 / 定价 / 标签 / 下架 */
    public function edit(Request $request, Book $book)
    {
        $this->authorize('update', $book);

        return view('author.books.settings', [
            'book' => $book->load('tags'),
            'licenseOptions' => Book::LICENSE_MAP,
        ]);
    }

    public function update(Request $request, Book $book): RedirectResponse
    {
        $this->authorize('update', $book);

        $validated = $request->validate([
            'license' => ['sometimes', 'integer', 'between:1,5'],
            'status' => ['sometimes', 'integer', 'between:0,3'],
        ]);

        if (isset($validated['license']) && $validated['license'] !== $book->license) {
            $prev = LicenseChange::where('book_id', $book->id)->latest('id')->first();

            LicenseChange::create([
                'book_id' => $book->id,
                'from_license' => $book->license,
                'to_license' => $validated['license'],
                'change_hash' => hash('sha256', $book->id.'|'.$book->license.'|'.$validated['license'].'|'.now()->timestamp),
                'prev_change_hash' => $prev?->change_hash,
            ]);
            $book->license = $validated['license'];
        }

        if (isset($validated['status'])) {
            // 下架：读者侧内容下线，存证记录保留（作者主权 spec）
            $book->status = $validated['status'];
        }

        $book->save();

        // 标签/状态变化 → 搜索索引同步（下架即从索引移除）
        SyncBookToSearchIndexJob::dispatch($book->id);

        return back()->with('status', '设置已保存');
    }

    /**
     * 一键下架（任务 11.4）：明确流程入口——作品立即对读者不可见（详情/正文/搜索/feed 全下线），
     * 存证记录与账本永久保留（版权证明不受下架影响）。audit_logs 留痕。
     */
    public function takeOffline(Request $request, Book $book): RedirectResponse
    {
        $this->authorize('update', $book);

        if ($book->status !== Book::STATUS_OFFLINE) {
            $book->update(['status' => Book::STATUS_OFFLINE]);

            AuditLogger::log(
                $request->user(), 'book.offline', 'book', $book->id,
                ['status' => Book::STATUS_ONGOING], ['status' => Book::STATUS_OFFLINE],
                '作者主动下架，存证记录保留',
            );

            SyncBookToSearchIndexJob::dispatch($book->id);
        }

        return back()->with('status', '作品已下架：读者侧内容已全部下线，存证记录永久保留（版权证明不受影响）');
    }

    private function storeCover(Request $request): ?string
    {
        if (! $request->hasFile('cover')) {
            return null;
        }

        // TODO: 接 OSS 直传（任务 4.1 完整版：前端直传 OSS + 回调）；MVP 先本地
        $path = $request->file('cover')->store('covers', 'public');

        return '/storage/'.$path;
    }
}
