<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ===== moderation_reports：举报与 DMCA 队列（任务 12.2/12.3）=====
        Schema::create('moderation_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reporter_id')->constrained('users')->cascadeOnDelete(); // 举报人
            $t->string('target_type', 16);              // comment / danmu / book
            $t->unsignedBigInteger('target_id');
            $t->unsignedTinyInteger('reason_kind');    // 0垃圾 1色情 2侵权 3DMCA 4其他
            $t->string('reason_text', 500)->nullable();  // 补充说明（DMCA 附权利证明）
            $t->unsignedTinyInteger('status')->default(0); // 0待处理 1已处理 2已忽略
            $t->foreignId('handler_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 16)->nullable();       // hide / offline / legal / none
            $t->string('handle_note', 500);            // 处理动作必须强制备注（任务 12.2）
            $t->timestamp('handled_at')->nullable();
            $t->timestamps();

            $t->index(['status', 'reason_kind', 'created_at']);
            $t->index(['target_type', 'target_id']);
        });

        // ===== site_settings：运营位配置（任务 12.5 免部署生效）=====
        Schema::create('site_settings', function (Blueprint $t) {
            $t->string('key', 64)->primary();
            $t->json('value');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('moderation_reports');
    }
};
