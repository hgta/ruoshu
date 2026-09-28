<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 后台访问防线（任务 12.1）：
 *  1. gate:admin（roles bitmap 含 ADMIN）
 *  2. IP 白名单（生产为 admin 独立子域 + 网关白名单，此处应用层双保险；空配置 = 不启用）
 *  3. TOTP 2FA 由 13.3/13.7 上线前接入（独立子域部署时随 SSO 落实）
 */
class EnsureAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $whitelist = array_filter(array_map('trim', explode(',', (string) config('features.admin_ip_whitelist'))));
        if ($whitelist !== [] && ! in_array($request->ip(), $whitelist, true)) {
            abort(403, '后台访问受限');
        }

        return $next($request);
    }
}
