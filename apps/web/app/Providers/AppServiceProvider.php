<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\AiPythonClient;
use App\Services\Evidence\EvidenceService;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Meilisearch\Client;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Python 边车客户端（单例，短超时）
        $this->app->singleton(AiPythonClient::class);
        // 存证链 driver 工厂
        $this->app->singleton(EvidenceService::class);

        // Meilisearch 客户端（未配置 host 时指向默认本地，连接失败由 SearchService 捕获降级 LIKE）
        $this->app->singleton(Client::class, function () {
            return new Client(
                (string) config('services.meilisearch.host', 'http://127.0.0.1:7700'),
                config('services.meilisearch.key'),
            );
        });
    }

    public function boot(): void
    {
        // Blade 角色指令：@role('author') / @role(2) ... @endrole
        Blade::if('role', function (string|int $role) {
            $bit = is_int($role) ? $role : [
                'reader' => 1, 'author' => 2, 'admin' => 4,
            ][$role] ?? 0;

            return auth()->check() && (bool) (auth()->user()->roles & $bit);
        });

        // 角色门面（角色 bitmap：1=reader 2=author 4=admin）
        Gate::define('author', fn ($user) => (bool) ($user->roles & 2));
        Gate::define('admin', fn ($user) => (bool) ($user->roles & 4));
    }
}
