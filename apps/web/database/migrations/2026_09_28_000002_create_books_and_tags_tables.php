<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ===== books：作品 =====
        Schema::create('books', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete(); // 作者
            $t->string('title', 128);
            $t->string('title_hash', 64);                  // SHA-256(规范化标题)，上链用
            $t->string('cover', 500)->nullable();          // OSS URL
            $t->text('intro')->nullable();                 // 简介（摘要用，≤200 字进 RSS）
            $t->unsignedInteger('category_id');           // 6 大类
            $t->unsignedTinyInteger('status')->default(0); // 0=草稿 1=连载 2=完结 3=下架
            $t->unsignedTinyInteger('license')->default(1); // 1=CC BY-NC-ND 2=CC BY-NC 3=CC BY-ND 4=保留所有权利 5=CC0
            $t->boolean('is_paid')->default(false);        // 是否有付费章节
            $t->unsignedInteger('word_count')->default(0);
            $t->unsignedInteger('chapter_count')->default(0);
            $t->unsignedInteger('read_count')->default(0);
            $t->unsignedBigInteger('collect_count')->default(0);
            $t->unsignedBigInteger('danmu_count')->default(0);
            $t->unsignedInteger('view_count')->default(0);
            $t->timestamps();

            $t->index(['status', 'category_id']);
            $t->index(['user_id', 'status']);
        });

        // ===== tags：自由标签 =====
        Schema::create('tags', function (Blueprint $t) {
            $t->id();
            $t->string('name', 32)->unique();
            $t->unsignedInteger('use_count')->default(0);
            $t->timestamps();
        });

        // ===== book_tags =====
        Schema::create('book_tags', function (Blueprint $t) {
            $t->id();
            $t->foreignId('book_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $t->unique(['book_id', 'tag_id']);
        });

        // ===== gifts：礼物定义（打赏仪式感，女频吃这套）=====
        Schema::create('gifts', function (Blueprint $t) {
            $t->id();
            $t->string('name', 32);                        // 小星星 / 海洋之心 / 月亮
            $t->string('icon', 500);                       // OSS URL
            $t->unsignedInteger('price');                  // 分（100=¥1）
            $t->unsignedInteger('sort')->default(0);
            $t->boolean('enabled')->default(true);
            $t->timestamps();
        });

        // ===== license_changes：授权变更历史（hash 记录，任务 11.1）=====
        Schema::create('license_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('book_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('from_license');
            $t->unsignedTinyInteger('to_license');
            $t->string('change_hash', 64);                 // SHA-256(book_id+from+to+time+prev)
            $t->string('prev_change_hash', 64)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_changes');
        Schema::dropIfExists('gifts');
        Schema::dropIfExists('book_tags');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('books');
    }
};
