<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ===== ledger_entries：唯一真源账本（所有金钱流动同事务落账，链式哈希）=====
        Schema::create('ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();             // 付款读者
            $t->foreignId('book_id')->constrained()->cascadeOnDelete();
            $t->foreignId('chapter_id')->nullable()->constrained()->nullOnDelete();
            $t->string('source_type', 32);                       // donation / purchase / subscription
            $t->unsignedBigInteger('source_id');                 // 来源记录 id
            $t->unsignedInteger('gross');                       // 毛额（分）
            $t->decimal('fee_rate', 5, 4);                       // 平台费率 0.05/0.15/0.10
            $t->unsignedInteger('fee');                          // 平台抽成（分）
            $t->unsignedInteger('net');                          // 作者净得（分）
            $t->string('entry_hash', 64);                        // SHA-256(本条关键字段 + prev_hash)
            $t->string('prev_hash', 64)->nullable();             // 链式前驱
            $t->foreignId('merkle_batch_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();

            $t->unique(['source_type', 'source_id']);            // 同事务幂等
            $t->index(['book_id', 'created_at']);
            $t->index('entry_hash');
        });

        // ===== donations：打赏（礼物 + 自由金额）=====
        Schema::create('donations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('book_id')->constrained()->cascadeOnDelete();
            $t->foreignId('chapter_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('gift_id')->nullable()->constrained()->nullOnDelete(); // null=自由金额
            $t->unsignedInteger('amount');                       // 分
            $t->string('pay_channel', 16);                       // wechat / alipay
            $t->string('pay_trade_no', 64)->unique();
            $t->unsignedTinyInteger('status')->default(0);       // 0=待支付 1=成功 2=失败/退款
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });

        // ===== chapter_purchases：章节单购（幂等 UNIQUE(user,chapter)）=====
        Schema::create('chapter_purchases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('book_id')->constrained()->cascadeOnDelete();
            $t->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('price');                        // 成交价（分）
            $t->string('pay_channel', 16);
            $t->string('pay_trade_no', 64)->unique();
            $t->unsignedTinyInteger('status')->default(0);
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();

            $t->unique(['user_id', 'chapter_id']);               // 幂等：重复购买不二次扣费
        });

        // ===== subscriptions：月卡订阅（覆盖 VIP 章节）=====
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('book_id')->constrained()->cascadeOnDelete(); // 书级月卡
            $t->unsignedInteger('price');                        // 分/月
            $t->string('pay_channel', 16);
            $t->string('pay_trade_no', 64)->unique();
            $t->unsignedTinyInteger('status')->default(0);        // 0=待支付 1=生效 2=过期 3=取消
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();

            $t->index(['user_id', 'book_id', 'status', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('chapter_purchases');
        Schema::dropIfExists('donations');
        Schema::dropIfExists('ledger_entries');
    }
};
