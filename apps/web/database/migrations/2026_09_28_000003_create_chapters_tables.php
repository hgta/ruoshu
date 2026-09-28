<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ===== chapters：章节元数据（热表，不含正文）=====
        Schema::create('chapters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('book_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('chapter_no');            // 章序
            $t->string('title', 128);
            $t->unsignedInteger('word_count')->default(0);
            $t->unsignedTinyInteger('status')->default(0); // 0=草稿 1=已发布 2=下架
            $t->boolean('is_paid')->default(false);        // VIP 章节标志
            $t->unsignedInteger('price')->default(20);      // 分（默认 0.2 元，作者可调 10-50）
            $t->unsignedTinyInteger('version')->default(1); // 修订版本（指纹快照版本）
            $t->timestamp('published_at')->nullable();
            $t->timestamps();

            $t->unique(['book_id', 'chapter_no']);         // 目录排序 + 幂等
            $t->index(['book_id', 'status', 'chapter_no']);
        });

        // ===== chapter_contents：正文（垂直拆分，冷数据）=====
        Schema::create('chapter_contents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('chapter_id')->unique()->constrained()->cascadeOnDelete();
            $t->mediumText('content');                      // 单章 ≤10 万字（utf8mb4 约 16MB 上限）
            $t->mediumText('author_note')->nullable();     // 作者有话说
            $t->timestamps();
        });

        // ===== paragraphs：段落指纹快照（发布后只读，按版本）=====
        Schema::create('paragraphs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            $t->foreignId('book_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('para_no');
            $t->unsignedTinyInteger('version')->default(1);
            $t->string('hash', 64);                        // SHA-256(规范化段落)
            $t->unsignedInteger('char_count');
            $t->timestamps();

            $t->unique(['chapter_id', 'version', 'para_no']);
            $t->index(['book_id', 'version']);
            $t->index('hash');                             // 盗版比对：按 hash 反查
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paragraphs');
        Schema::dropIfExists('chapter_contents');
        Schema::dropIfExists('chapters');
    }
};
