<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ===== comments：多态评论（段评/章评/书评 + 一层楼中楼）=====
        Schema::create('comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('book_id')->index();          // 冗余，便于书评区拉取
            $t->unsignedBigInteger('chapter_id')->nullable()->index();
            $t->string('target_type', 16);                        // paragraph / chapter / book
            $t->unsignedBigInteger('target_id');                  // 段: paragraphs.id 章: chapters.id 书: books.id
            $t->unsignedBigInteger('parent_id')->nullable();      // 一层楼中楼
            $t->text('content');                                   // ≤500 字
            $t->unsignedTinyInteger('status')->default(1);        // 0=隐藏 1=正常 2=删除
            $t->unsignedInteger('like_count')->default(0);
            $t->unsignedInteger('reply_count')->default(0);
            $t->unsignedBigInteger('reply_to_user_id')->nullable(); // 楼中楼被回复人
            $t->timestamps();

            $t->index(['target_type', 'target_id', 'status', 'id']);
            $t->index(['chapter_id', 'status']);
        });

        // ===== danmu：弹幕（实时流，Redis 热存 + 本表归档）=====
        Schema::create('danmu', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('book_id')->constrained()->cascadeOnDelete();
            $t->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('paragraph_no');               // 绑段落位置
            $t->string('content', 100);
            $t->unsignedTinyInteger('status')->default(1);        // 0=隐藏 1=正常
            $t->timestamp('archived_at')->nullable();             // Redis→MySQL 归档时间
            $t->timestamps();

            $t->index(['chapter_id', 'status']);
            $t->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('danmu');
        Schema::dropIfExists('comments');
    }
};
