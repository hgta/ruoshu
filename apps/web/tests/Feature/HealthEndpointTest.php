<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;

/*
|--------------------------------------------------------------------------
| 任务 13.5 探针端点：LB/监控挂点
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

it('healthz 存活：不碰依赖恒 200；readyz 就绪：DB+Redis 通则 200', function () {
    $this->getJson('/healthz')
        ->assertOk()
        ->assertJson(['status' => 'ok']);

    Redis::shouldReceive('ping')->andReturn('+PONG');

    $this->getJson('/readyz')
        ->assertOk()
        ->assertJsonPath('checks.db', 'ok')
        ->assertJsonPath('checks.redis', 'ok');
});

it('readyz 依赖故障时返回 503（degraded，供 LB 摘除与告警）', function () {
    Redis::shouldReceive('ping')->andThrow(new RuntimeException('connection refused'));

    $this->getJson('/readyz')
        ->assertStatus(503)
        ->assertJson(['status' => 'degraded']);
});
