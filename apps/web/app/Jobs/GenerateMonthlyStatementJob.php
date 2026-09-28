<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LedgerEntry;
use App\Models\MonthlyStatement;
use App\Models\User;
use App\Services\AiPythonClient;
use App\Services\Payment\TsaService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 月度对账单生成（任务 10.7）：
 * 汇总作者上月账本（毛/抽/净/笔数）→ 边车生成 PDF → TSA 时间戳 → 存储。
 * 每月 1 日调度触发（也可财务手动触发指定 period）。
 */
class GenerateMonthlyStatementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct(
        public int $authorId,
        public string $period, // YYYY-MM
    ) {}

    public function handle(AiPythonClient $ai, TsaService $tsa): void
    {
        $statement = MonthlyStatement::updateOrCreate(
            ['user_id' => $this->authorId, 'period' => $this->period],
            ['status' => MonthlyStatement::ST_RUNNING, 'gross' => 0, 'fee' => 0, 'net' => 0, 'entry_count' => 0],
        );

        try {
            // 1. 汇总该作者本周期账本
            $bounds = [$this->period.'-01 00:00:00', Carbon::parse($this->period.'-01')->endOfMonth()->endOfDay()->toDateTimeString()];
            $summary = LedgerEntry::whereHas('book', fn ($q) => $q->where('user_id', $this->authorId))
                ->whereBetween('created_at', $bounds)
                ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(gross),0) AS gross, COALESCE(SUM(fee),0) AS fee, COALESCE(SUM(net),0) AS net')
                ->first();

            // 2. 边车生成 PDF
            $pdf = $ai->statementPdf([
                'author_id' => $this->authorId,
                'author_name' => User::find($this->authorId)?->name ?? "作者#{$this->authorId}",
                'period' => $this->period,
                'summary' => [
                    'entry_count' => (int) $summary->cnt,
                    'gross' => (int) $summary->gross,
                    'fee' => (int) $summary->fee,
                    'net' => (int) $summary->net,
                ],
                'entries' => LedgerEntry::whereHas('book', fn ($q) => $q->where('user_id', $this->authorId))
                    ->whereBetween('created_at', $bounds)
                    ->orderBy('id')->limit(200)
                    ->get()->map(fn (LedgerEntry $e) => [
                        'book' => $e->book?->title ?? "书#{$e->book_id}",
                        'source' => $this->sourceName($e->source_type),
                        'gross' => $e->gross,
                        'fee' => $e->fee,
                        'net' => $e->net,
                        'hash' => $e->entry_hash,
                        'at' => $e->created_at->toIso8601String(),
                    ])->all(),
            ]);

            // 3. TSA 时间戳
            $stamp = $tsa->timestamp($pdf);

            // 4. 存储
            $key = "statements/{$statement->id}-{$this->period}.pdf";
            Storage::disk(config('services.forensic.disk', 'local'))->put($key, $pdf);

            $statement->update([
                'gross' => (int) $summary->gross,
                'fee' => (int) $summary->fee,
                'net' => (int) $summary->net,
                'entry_count' => (int) $summary->cnt,
                'pdf_path' => $key,
                'tsa_token' => $stamp['token'],
                'tsa_source' => $stamp['source'],
                'tsa_time' => $stamp['time'],
                'status' => MonthlyStatement::ST_DONE,
            ]);

            Log::info('月度对账单生成完成', ['statement_id' => $statement->id, 'period' => $this->period]);
        } catch (Throwable $e) {
            $statement->update(['status' => MonthlyStatement::ST_FAILED]);
            throw $e;
        }
    }

    private function sourceName(string $type): string
    {
        return ['donation' => '打赏', 'purchase' => '单购', 'subscription' => '月卡'][$type] ?? $type;
    }
}
