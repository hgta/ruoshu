<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ===== monthly_statements：作者月度对账单（任务 10.7）=====
        // 唯一 (user_id, period)：同一作者同一月份只生成一份（重复触发 = 重算覆盖前的旧单据状态）
        Schema::create('monthly_statements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();   // 作者
            $t->string('period', 7);                                        // YYYY-MM
            $t->unsignedInteger('gross');                                   // 毛额合计（分）
            $t->unsignedInteger('fee');                                      // 平台抽成合计（分）
            $t->unsignedInteger('net');                                     // 净得合计（分）
            $t->unsignedInteger('entry_count');                              // 账笔数
            $t->string('pdf_path', 255)->nullable();                         // 存储 key（生产 OSS / 开发本地）
            $t->string('tsa_token', 512)->nullable();                        // TSA 时间戳令牌（RFC 3161）
            $t->string('tsa_source', 16)->default('local');                  // local / remote
            $t->timestamp('tsa_time')->nullable();
            $t->unsignedTinyInteger('status')->default(0);                   // 0=生成中 1=完成 2=失败
            $t->timestamps();

            $t->unique(['user_id', 'period']);
        });

        // ===== withdrawals：作者提现申请（任务 10.8，flag 控制）=====
        // 可提现余额 = SUM(ledger.net) - 已提现(paid) - 审核中(pending)
        Schema::create('withdrawals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();   // 作者
            $t->unsignedInteger('amount');                                  // 申请金额（分）
            $t->string('trade_no', 64)->unique();                           // 打款流水
            $t->unsignedTinyInteger('status')->default(0);                  // 0=审核中 1=已通过待打款 2=已打款 3=已驳回
            $t->string('review_note', 500)->nullable();                     // 财务备注（驳回必填）
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();

            $t->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('monthly_statements');
    }
};
