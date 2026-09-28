<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feature flag 门卫：控制上线顺序的硬开关。
 *  - registration=false 时封禁注册入口（敏感词库未就绪前不开注册）
 *  - payment=false 时支付相关 API 返回 503（法务未过审前不开支付）
 */
class FeatureFlagGate
{
    public function handle(Request $request, Closure $next): Response
    {
        // 注册开关（敏感词库未就绪前不开注册，任务 13.1 前置）
        if (! config('features.registration') && $this->isRegisterRoute($request)) {
            return response()->view('errors.503-closed', [
                'message' => '注册暂未开放，敬请期待',
            ], 503);
        }

        // 支付开关：仅封支付入口，不影响页面浏览（法务未过审前不开支付）
        if (! config('features.payment') && $this->isPaymentRoute($request)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'code' => 'PAYMENT_UNAVAILABLE',
                    'message' => '支付功能即将开放',
                ], 503);
            }

            return response()->view('errors.503-closed', [
                'message' => '支付功能即将开放',
            ], 503);
        }

        return $next($request);
    }

    private function isRegisterRoute(Request $r): bool
    {
        return $r->is('register') || $r->is('register/*') || $r->is('api/register*');
    }

    private function isPaymentRoute(Request $r): bool
    {
        return $r->is('pay/*') || $r->is('api/pay*') || $r->is('donate*')
            || $r->is('api/donate*') || $r->is('api/purchase*') || $r->is('api/subscribe*');
    }
}
