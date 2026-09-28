<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reading;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\Paragraph;
use App\Services\SensitiveWordFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 多态评论（任务 9.1）：段评 / 章评 / 书评 + 一层楼中楼 + 点赞 + 敏感词过滤。
 * 前端 JSON 接口（乐观 UI 即时上屏），页面降级由 9.4 轮询兜底。
 */
class CommentController extends Controller
{
    public function __construct(private readonly SensitiveWordFilter $filter) {}

    /** 发布评论（段/章/书 + 可选楼中楼回复） */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'target_type' => ['required', 'in:paragraph,chapter,book'],
            'target_id' => ['required', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'reply_to_user_id' => ['nullable', 'integer'],
            'content' => ['required', 'string', 'min:1', 'max:500'],
        ]);

        // 目标合法性（按类型校验存在 + 拿冗余 book/chapter id）
        [$bookId, $chapterId] = $this->resolveTarget($validated['target_type'], (int) $validated['target_id']);

        // 一层楼中楼：parent 必须是同目标下的顶层评论（不许楼中楼套楼中楼）
        $parent = null;
        if (! empty($validated['parent_id'])) {
            $parent = Comment::where('id', $validated['parent_id'])
                ->where('target_type', $validated['target_type'])
                ->where('target_id', $validated['target_id'])
                ->whereNull('parent_id') // 一层限制
                ->firstOrFail();
        }

        // 敏感词：发布侧拦截
        $hit = $this->filter->firstHit($validated['content']);
        if ($hit !== null) {
            return response()->json(['message' => "评论包含敏感词「{$hit}」，请修改"], 422);
        }

        $comment = Comment::create([
            'user_id' => $request->user()->id,
            'book_id' => $bookId,
            'chapter_id' => $chapterId,
            'target_type' => $validated['target_type'],
            'target_id' => (int) $validated['target_id'],
            'parent_id' => $parent?->id,
            'reply_to_user_id' => $validated['reply_to_user_id'] ?? null,
            'content' => $validated['content'],
            'status' => Comment::ST_NORMAL,
        ]);

        if ($parent) {
            $parent->increment('reply_count');
        }

        return response()->json([
            'id' => $comment->id,
            'content' => $comment->content,
            'user' => ['id' => $comment->user->id, 'name' => $comment->user->name],
            'reply_count' => 0,
            'like_count' => 0,
            'created_at' => $comment->created_at?->toIso8601String(),
        ], 201);
    }

    /** 目标评论列表（顶层 + 楼中楼计数） */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'target_type' => ['required', 'in:paragraph,chapter,book'],
            'target_id' => ['required', 'integer'],
        ]);

        $comments = Comment::where('target_type', $validated['target_type'])
            ->where('target_id', $validated['target_id'])
            ->where('status', Comment::ST_NORMAL)
            ->whereNull('parent_id')
            ->with('user:id,name,avatar')
            ->withCount('replies')
            ->latest('id')
            ->paginate(20);

        return response()->json($comments);
    }

    /** 楼中楼列表（一层） */
    public function replies(Request $request, Comment $comment): JsonResponse
    {
        abort_unless($comment->status === Comment::ST_NORMAL, 404);

        $replies = $comment->replies()
            ->where('status', Comment::ST_NORMAL)
            ->with(['user:id,name,avatar', 'replyTo:id,name'])
            ->oldest('id')
            ->limit(100)
            ->get();

        return response()->json($replies);
    }

    /** 点赞 toggle（Redis set 防重复） */
    public function like(Request $request, Comment $comment): JsonResponse
    {
        abort_unless($comment->status === Comment::ST_NORMAL, 404);

        $key = "comment-likes:{$comment->id}";
        $liked = Cache::get($key, []);

        if (in_array($request->user()->id, $liked)) {
            // 取消
            $liked = array_values(array_diff($liked, [$request->user()->id]));
            $comment->decrement('like_count');
            Cache::forever($key, $liked);

            return response()->json(['liked' => false, 'like_count' => max(0, $comment->like_count)]);
        }

        $liked[] = $request->user()->id;
        $comment->increment('like_count');
        Cache::forever($key, $liked);

        return response()->json(['liked' => true, 'like_count' => $comment->like_count]);
    }

    /** 删除自己的评论（软：status=2，保留楼层计数） */
    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        abort_unless($comment->user_id === $request->user()->id, 403);

        $comment->update(['status' => Comment::ST_DELETED]);
        if ($comment->parent_id) {
            $comment->parent->decrement('reply_count');
        }

        return response()->json(['deleted' => true]);
    }

    /** @return array{0:int,1:?int} [book_id, chapter_id] */
    private function resolveTarget(string $type, int $id): array
    {
        return match ($type) {
            'book' => [Book::where('status', '!=', Book::STATUS_OFFLINE)->findOrFail($id)->id, null],
            'chapter' => tap(
                [0, 0],
                function (&$out) use ($id) {
                    $ch = Chapter::where('status', Chapter::STATUS_PUBLISHED)->findOrFail($id);
                    $out = [$ch->book_id, $ch->id];
                }
            ),
            // 段评：paragraphs 行有 book_id/chapter 冗余
            'paragraph' => tap([0, null], function (&$out) use ($id) {
                $p = Paragraph::with('chapter:id,book_id')->findOrFail($id);
                $out = [$p->book_id, $p->chapter_id];
            }),
            default => abort(404),
        };
    }
}
