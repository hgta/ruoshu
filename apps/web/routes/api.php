<?php

declare(strict_types=1);

use App\Http\Controllers\Reading\BookshelfController;
use App\Models\Chapter;
use App\Services\Reading\AccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ===== API（进度上报 / 后续段评、弹幕轮询降级端点）=====

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/me/progress', [BookshelfController::class, 'reportProgress']);
    Route::post('/me/bookshelf/toggle', [BookshelfController::class, 'toggle']);
});

// 阅读门禁 API 化（前端解锁面板轮询）
Route::get('/chapters/{chapter}/access', function (Chapter $chapter, Request $req) {
    $ok = app(AccessService::class)->canRead($req->user(), $chapter);

    return response()->json([
        'readable' => $ok,
        'reason' => $ok ? null : app(AccessService::class)->denialReason($req->user(), $chapter),
        'price' => $chapter->is_paid ? $chapter->price : 0,
    ]);
})->whereNumber('chapter');
