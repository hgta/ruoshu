<?php

declare(strict_types=1);

use App\Http\Middleware\FeatureFlagGate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

/*
|--------------------------------------------------------------------------
| Laravel 11 引导（fluent builder）
|--------------------------------------------------------------------------
| 骨架约定：bootstrap/providers.php 自动加载；路由在 routes/ 注册。
*/

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Feature flag 硬开关：registration / payment（上线顺序控制）
        $middleware->web(append: [
            FeatureFlagGate::class,
        ]);
        $middleware->api(prepend: [
            FeatureFlagGate::class,
        ]);

        // 支付渠道回调豁免 CSRF（生产为微信/支付宝服务器间调用，以验签代替 CSRF）；
        // 结算入口幂等，重放安全。
        $middleware->validateCsrfTokens(except: [
            'pay/*/callback',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
