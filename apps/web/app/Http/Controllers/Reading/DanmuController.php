<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reading;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\Danmu;
use App\Services\Reading\AccessService;
use App\Services\Realtime\RealtimeBroadcaster;
use App\Services\SensitiveWordFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 弹幕（任务 9.3）：
 *  - 发布：敏感词拦截 → DB 归档 + 热存 unshift（cap 200）→ Redis 频道广播（乐观 UI 佐证）
 *  - 列表：热存命中直接返回；miss 回源 DB 最新 50 并回填
 * 列表公开（游客可看），发布需登录。
 */
class DanmuController extends Controller
{
    private const HOT_TTL = 600; // 热存 10 分钟

    private const HOT_CAP = 200; // 热存容量

    private const DB_SOURCE = 50; // 回源条数

    public function __construct(
        private readonly AccessService $access,
        private readonly SensitiveWordFilter $filter,
        private readonly RealtimeBroadcaster $broadcaster,
    ) {}

    /** 发布弹幕 */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chapter_id' => ['required', 'integer'],
            'paragraph_no' => ['nullable', 'integer', 'min:1'],
            'content' => ['required', 'string', 'min:1', 'max:50'],
        ]);

        $chapter = Chapter::where('status', Chapter::STATUS_PUBLISHED)->findOrFail($validated['chapter_id']);
        if (! $this->access->canRead($request->user(), $chapter)) {
            return response()->json(['message' => '尚未解锁该章节'], 403);
        }

        // 敏感词：发布侧拦截
        $hit = $this->filter->firstHit($validated['content']);
        if ($hit !== null) {
            return response()->json(['message' => "弹幕包含敏感词「{$hit}」，请修改"], 422);
        }

        $danmu = Danmu::create([
            'user_id' => $request->user()->id,
            'book_id' => $chapter->book_id,
            'chapter_id' => $chapter->id,
            'paragraph_no' => $validated['paragraph_no'] ?? 1,
            'content' => $validated['content'],
            'status' => Danmu::ST_NORMAL,
        ]);

        // 热存 unshift（列表页首屏）
        $key = "danmu:hot:{$chapter->id}";
        $hot = Cache::get($key, []);
        array_unshift($hot, $this->present($danmu, $request->user()->name));
        $hot = array_slice($hot, 0, self::HOT_CAP);
        Cache::put($key, $hot, now()->addSeconds(self::HOT_TTL));

        // 广播（同章节订阅者实时渲染；失败降级轮询）
        $this->broadcaster->toChapter($chapter->book_id, $chapter->id, 'danmu', [
            'id' => $danmu->id,
            'content' => $danmu->content,
            'user' => $request->user()->name,
            'paragraph_no' => $danmu->paragraph_no,
        ]);

        return response()->json($this->present($danmu, $request->user()->name), 201);
    }

    /** 弹幕列表（游客可看，任务 9.4 轮询降级的拉取端点） */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'chapter_id' => ['required', 'integer'],
            'after_id' => ['nullable', 'integer'], // 轮询增量
        ]);
        $chapterId = $validated['chapter_id'];

        // 热存命中：直接返回（可含增量过滤）
        $key = "danmu:hot:{$chapterId}";
        $hot = Cache::get($key);
        if ($hot !== null) {
            $items = isset($validated['after_id'])
                ? array_values(array_filter($hot, fn ($d) => $d['id'] > (int) $validated['after_id']))
                : $hot;

            return response()->json(['danmu' => $items, 'source' => 'hot']);
        }

        // miss：回源 DB 最新 50（旧→新）并回填
        $rows = Danmu::where('chapter_id', $chapterId)
            ->where('status', Danmu::ST_NORMAL)
            ->orderByDesc('id')
            ->limit(self::DB_SOURCE)
            ->with('user:id,name')
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Danmu $d) => $this->present($d, $d->user?->name ?? '书友'))
            ->all();

        Cache::put($key, $rows, now()->addSeconds(self::HOT_TTL));

        $items = isset($validated['after_id'])
            ? array_values(array_filter($rows, fn ($d) => $d['id'] > (int) $validated['after_id']))
            : $rows;

        return response()->json(['danmu' => $items, 'source' => 'db']);
    }

    /** @return array{id:int,content:string,user:string,paragraph_no:int} */
    private function present(Danmu $d, string $name): array
    {
        return [
            'id' => $d->id,
            'content' => $d->content,
            'user' => $name,
            'paragraph_no' => (int) $d->paragraph_no,
        ];
    }
}
