<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// 维护模式检查（Laravel 11 由框架内部处理 maintenance 中间件）

// Composer 自动加载
require __DIR__.'/../vendor/autoload.php';

// 引导应用并处理请求
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
