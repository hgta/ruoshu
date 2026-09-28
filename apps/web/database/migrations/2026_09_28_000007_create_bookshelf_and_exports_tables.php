<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ===== bookshelves：书架 =====
        Schema::create('bookshelves', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('book_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('last_read_chapter_id')->nullable();
            $t->unsignedInteger('last_read_para_no')->default(0);   // 段落级进度
            $t->unsignedInteger('last_read_offset')->default(0);   // 段内偏移
            $t->timestamp('last_read_at')->nullable();
            $t->timestamps();

            $t->unique(['user_id', 'book_id']);
            $t->index(['user_id', 'last_read_at']);
        });

        // ===== exports：作者导出任务（异步 → OSS 签名 URL 72h）=====
        Schema::create('exports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('book_id')->constrained()->cascadeOnDelete();
            $t->string('format', 8);                               // md / txt / zip
            $t->unsignedTinyInteger('status')->default(0);        // 0=排队 1=生成中 2=完成 3=失败
            $t->string('oss_key', 500)->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();

            $t->index(['user_id', 'status']);
        });

        // ===== audit_logs：管理操作审计（append-only）=====
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $t->string('action', 64);                              // comment.hide / book.takedown ...
            $t->string('target_type', 32);
            $t->unsignedBigInteger('target_id');
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->string('note', 500)->nullable();
            $t->string('actor_sig', 128)->nullable();               // HMAC(actor_id+action+time)
            $t->timestamps();

            $t->index(['target_type', 'target_id']);
            $t->index(['actor_id', 'created_at']);
        });

        // ===== dmca_takedowns：举报与 DMCA 处理 =====
        Schema::create('dmca_takedowns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('target_type', 32);                          // book / chapter / comment
            $t->unsignedBigInteger('target_id');
            $t->string('reason', 500);
            $t->text('detail')->nullable();
            $t->foreignId('evidence_record_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedTinyInteger('status')->default(0);         // 0=待triage 1=处理中 2=已下架 3=驳回 4=转法务
            $t->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('handle_note', 500)->nullable();
            $t->timestamps();

            $t->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dmca_takedowns');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('exports');
        Schema::dropIfExists('bookshelves');
    }
};
