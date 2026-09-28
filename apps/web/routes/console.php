<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 控制台调度（定时任务）
|--------------------------------------------------------------------------
| 队列消费由 Horizon/supervisor 托管；此处只注册计划任务。
*/

use App\Jobs\ArchiveDanmuJob;
use App\Jobs\BatchDailyEvidenceJob;
use App\Jobs\GenerateMonthlyStatementJob;
use App\Jobs\QueueHealthCheckJob;
use App\Models\Book;
use Illuminate\Support\Facades\Schedule;

// 免费书每日打包存证（至信链分级策略：免费 1 条/天）
Schedule::job(new BatchDailyEvidenceJob)
    ->daily()->at('02:00')
    ->name('free-books-daily-evidence');

// 弹幕 Redis 热存 → MySQL 归档（15 分钟窗口）
Schedule::job(new ArchiveDanmuJob)
    ->everyFiveMinutes()
    ->name('danmu-archive');

// 队列积压 / 存证死信巡检（监控挂点）
Schedule::job(new QueueHealthCheckJob)
    ->everyFiveMinutes()
    ->name('queue-health-check');

// 月度对账单：每月 1 日为所有作者生成上月账单（任务 10.7）
Schedule::call(function () {
    $period = now()->subMonth()->format('Y-m');
    Book::where('status', 1)->distinct()->pluck('user_id')
        ->each(fn ($id) => GenerateMonthlyStatementJob::dispatch($id, $period));
})
    ->monthlyOn(1, '02:30')
    ->name('monthly-statements');
