<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reading;

use App\Http\Controllers\Controller;
use App\Services\Realtime\RealtimeTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WebSocket 握手令牌（任务 9.2）：
 * 前端先取本接口的 60s RS256 短令牌，再带 token+book_id+chapter_id 连 Go 网关 /ws。
 */
class RealtimeTokenController extends Controller
{
    public function __invoke(Request $request, RealtimeTokenService $tokens): JsonResponse
    {
        return response()->json([
            'token' => $tokens->issue($request->user()),
            'ws_url' => config('services.realtime.ws_url'),
            'expires_in' => 60,
        ]);
    }
}
