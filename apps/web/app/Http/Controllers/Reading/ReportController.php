<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reading;

use App\Http\Controllers\Controller;
use App\Models\ModerationReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 读者举报入口（任务 12.2 数据源）：评论/弹幕/作品。
 * 登录即可举报（游客先登录——举报要担责，防滥用）；同人对同目标限一条。
 */
class ReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'target_type' => ['required', 'in:comment,danmu,book'],
            'target_id' => ['required', 'integer'],
            'reason_kind' => ['required', 'integer', 'between:0,4'],
            'reason_text' => ['nullable', 'string', 'max:500'],
        ]);

        $exists = ModerationReport::where('reporter_id', $request->user()->id)
            ->where('target_type', $validated['target_type'])
            ->where('target_id', $validated['target_id'])
            ->exists();
        if ($exists) {
            return response()->json(['message' => '你已举报过该内容，审核处理中'], 409);
        }

        ModerationReport::create([
            'reporter_id' => $request->user()->id,
            'target_type' => $validated['target_type'],
            'target_id' => $validated['target_id'],
            'reason_kind' => (int) $validated['reason_kind'],
            'reason_text' => $validated['reason_text'] ?? null,
            'handle_note' => '', // 处理时由审核人强制填写
        ]);

        return response()->json(['message' => '已收到举报，审核团队会尽快处理'], 201);
    }
}
