<?php

declare(strict_types=1);
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest 测试基线
|--------------------------------------------------------------------------
| Feature 测试绑定 Laravel TestCase（启动容器，facade 可用）。
*/

uses(TestCase::class)->in('Feature');
