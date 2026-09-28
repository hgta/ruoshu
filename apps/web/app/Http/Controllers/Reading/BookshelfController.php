<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reading;

use App\Http\Controllers\Controller;
use App\Models\Bookshelf;
use App\Models\Chapter;
use App\Services\Reading\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookshelfController extends Controller
{
    /** 我的书架（跨设备进度同步） */
    public function index(Request $request)
    {
        $shelf = Bookshelf::where('user_id', $request->user()->id)
            ->with(['book:id,title,cover,status,chapter_count', 'book.chapters' => fn ($q) => $q->latest('chapter_no')->limit(1)])
            ->orderByDesc('last_read_at')
            ->paginate(50);

        return view('me.bookshelf', ['shelf' => $shelf]);
    }

    /** 收藏/移出书架 */
    public function toggle(Request $request): JsonResponse
    {
        $validated = $request->validate(['book_id' => 'required|integer|exists:books,id']);

        $shelf = Bookshelf::where('user_id', $request->user()->id)
            ->where('book_id', $validated['book_id'])
            ->first();

        if ($shelf) {
            $shelf->delete();

            return response()->json(['in_shelf' => false]);
        }

        Bookshelf::create([
            'user_id' => $request->user()->id,
            'book_id' => $validated['book_id'],
            'last_read_at' => now(),
        ]);

        return response()->json(['in_shelf' => true]);
    }

    /** 前端定期上报阅读位置（跨设备恢复，任务 5.5） */
    public function reportProgress(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chapter_id' => ['required', 'integer', 'exists:chapters,id'],
            'para_no' => ['sometimes', 'integer', 'min:0'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        $chapter = Chapter::findOrFail($validated['chapter_id']);

        app(AccessService::class)->touchProgress(
            $request->user(),
            $chapter,
            $validated['para_no'] ?? 0,
            $validated['offset'] ?? 0,
        );

        return response()->json(['ok' => true]);
    }
}
