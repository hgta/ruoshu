<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ===== users：账号基表（角色 bitmap：1=reader 2=author 4=admin）=====
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name', 64)->unique();              // 自动昵称或自取笔名
            $t->string('email', 191)->nullable()->unique();
            $t->string('phone', 20)->nullable()->unique();
            $t->string('password', 255)->nullable();       // 微信登录用户无密码
            $t->string('avatar', 500)->nullable();         // OSS URL
            $t->unsignedTinyInteger('roles')->default(1);  // bitmap
            $t->unsignedTinyInteger('status')->default(1); // 1=normal 2=banned
            $t->string('wechat_openid', 64)->nullable()->unique();
            $t->string('wechat_unionid', 64)->nullable()->index();
            $t->rememberToken();
            $t->timestamps();
        });

        // ===== reader_profiles：读者扩展 =====
        Schema::create('reader_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->unsignedInteger('coin_balance')->default(0); // 充值余额（分）
            $t->unsignedInteger('total_donated')->default(0);
            $t->timestamps();
        });

        // ===== author_profiles：作者扩展（实名哈希脱敏）=====
        Schema::create('author_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('pen_name', 64)->index();            // 笔名（对外展示）
            $t->string('real_name_hash', 64)->nullable();   // SHA-256(实名+盐)
            $t->string('real_name_masked', 32)->nullable(); // 脱敏展示：张*三
            $t->string('id_number_hash', 64)->nullable();
            $t->unsignedTinyInteger('verify_status')->default(0); // 0=未认证 1=待审 2=已认证 3=驳回
            $t->string('bio', 500)->nullable();
            $t->unsignedInteger('follower_count')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('author_profiles');
        Schema::dropIfExists('reader_profiles');
        Schema::dropIfExists('users');
    }
};
