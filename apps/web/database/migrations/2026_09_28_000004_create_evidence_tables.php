<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ===== evidence_records：本地证据先行持久化（MySQL + OSS 留底）=====
        Schema::create('evidence_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('book_id')->nullable()->constrained()->nullOnDelete(); // 批次级存证无单一 book
            $t->foreignId('chapter_id')->nullable()->constrained()->nullOnDelete(); // 打包存证为 null
            $t->unsignedTinyInteger('kind')->default(1);   // 1=逐章 2=每日打包 3=月度对账单 4=取证包
            $t->string('title_hash', 64)->nullable();      // 标题 hash（不含明文）
            $t->string('merkle_root', 64);                 // 本地 Merkle root（先行留底）
            $t->unsignedInteger('word_count')->nullable();
            $t->string('prev_chapter_root', 64)->nullable(); // 章节链式引用
            $t->unsignedInteger('version')->default(1);
            $t->unsignedTinyInteger('status')->default(0); // 0=pending 1=submitted 2=confirmed 3=retrying 4=dead
            $t->string('chain_name', 32)->nullable();      // zhixin / antchain / tsa
            $t->string('tx_id', 128)->nullable();          // 链上交易/存证号
            $t->string('cert_no', 128)->nullable();         // 存证证书编号（公开验证页用）
            $t->unsignedInteger('retry_count')->default(0);
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->json('payload')->nullable();               // 提交载荷留底（去敏后）
            $t->timestamps();

            $t->index(['status', 'retry_count']);
            $t->index(['book_id', 'chapter_id']);
        });

        // ===== merkle_batches：批次上链（免费书日批 / 对账单月批；预留链上锚定）=====
        Schema::create('merkle_batches', function (Blueprint $t) {
            $t->id();
            $t->unsignedTinyInteger('kind')->default(1);   // 1=章节日批 2=ledger月批
            $t->string('merkle_root', 64);
            $t->unsignedInteger('entry_count');
            $t->string('chain_name', 32)->nullable();
            $t->string('tx_id', 128)->nullable();
            $t->unsignedTinyInteger('status')->default(0);
            $t->timestamp('anchored_at')->nullable();
            $t->timestamps();
        });

        // ===== watermark_seeds：per-user 水印种子（(user,chapter) 确定性）=====
        Schema::create('watermark_seeds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('book_id')->constrained()->cascadeOnDelete();
            $t->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('payload');              // 52-bit payload（Python 边车生成）
            $t->json('anchor_plan')->nullable();           // 发布时预计算锚点方案
            $t->timestamps();

            $t->unique(['chapter_id', 'user_id']);          // 幂等：同章节同用户一个种子
        });

        // ===== watermark_audits：溯源审计 =====
        Schema::create('watermark_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('book_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable();          // 嫌疑账号（低置信时为 null）
            $t->text('pirate_text_excerpt');                // 盗版文本摘录（≤2000 字）
            $t->string('pirate_source', 500)->nullable();   // 盗版链接
            $t->decimal('confidence', 5, 4);               // 0-1
            $t->unsignedTinyInteger('match_kind')->default(1); // 1=水印 2=指纹 3=人工
            $t->unsignedTinyInteger('status')->default(0); // 0=新 1=已确认 2=误报 3=转法务
            $t->foreignId('evidence_record_id')->nullable()->constrained()->nullOnDelete(); // 取证包关联
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watermark_audits');
        Schema::dropIfExists('watermark_seeds');
        Schema::dropIfExists('merkle_batches');
        Schema::dropIfExists('evidence_records');
    }
};
