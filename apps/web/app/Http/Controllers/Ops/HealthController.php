<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ops;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * 探针端点（任务 13.5：LB 健康检查 + 监控告警挂点）。
 * /healthz 存活（不碰依赖）；/readyz 就绪（DB/Redis 通断）。
 */
class HealthController extends Controller
{
    public function healthz(): JsonResponse
    {
        return response()->json(['status' => 'ok'], 200, ['Cache-Control' => 'no-store']);
    }

    public function readyz(): JsonResponse
    {
        $checks = [];

        try {
            DB::select('SELECT 1');
            $checks['db'] = 'ok';
        } catch (\Throwable $e) {
            $checks['db'] = 'fail:'.$e->getCode();
        }

        try {
            Redis::ping();
            $checks['redis'] = 'ok';
        } catch (\Throwable $e) {
            $checks['redis'] = 'fail';
        }

        $ok = ! str_contains(implode('', $checks), 'fail');

        return response()->json(
            ['status' => $ok ? 'ready' : 'degraded', 'checks' => $checks],
            $ok ? 200 : 503,
            ['Cache-Control' => 'no-store'],
        );
    }
}
