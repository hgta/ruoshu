<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Chapter;
use App\Models\EvidenceRecord;
use App\Models\Export;
use App\Models\Paragraph;
use App\Models\User;
use App\Models\WatermarkAudit;
use App\Services\AiPythonClient;
use App\Services\Reading\ParagraphNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * 取证包 PDF 生成（任务 7.5）：
 * 异步队列 → 汇集指纹/水印/存证结论 → Python 边车生成 PDF →
 * 存储（生产 OSS / 开发本地）→ 72h 签名 URL → 站内（工作台列表）通知送达。
 */
class GenerateForensicPackageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $userId,
        public int $auditId,
        public ?string $pirateSource = null,
    ) {}

    public function handle(AiPythonClient $ai, ParagraphNormalizer $normalizer): void
    {
        $audit = WatermarkAudit::findOrFail($this->auditId);
        $book = $audit->book;

        $export = Export::create([
            'user_id' => $this->userId,
            'book_id' => $book->id,
            'format' => 'pdf',
            'status' => Export::ST_RUNNING,
        ]);

        try {
            // 1. 从审计摘录重跑指纹匹配（不依赖 session 内状态，队列可重放）
            $hashes = $normalizer->hashAll($audit->pirate_text_excerpt);
            $paraHits = Paragraph::query()
                ->whereIn('hash', array_values($hashes))
                ->with('chapter:id,book_id,title')
                ->get();

            $chapterIds = $paraHits->pluck('chapter_id')->unique()->values();

            // 2. 相关存证记录
            $evidence = EvidenceRecord::query()
                ->whereIn('chapter_id', $chapterIds)
                ->orderByDesc('id')
                ->limit(20)
                ->get();

            // 3. 组装报告 → 边车生成 PDF
            $pdf = $ai->forensicPdf([
                'book_title' => $book->title,
                'book_id' => $book->id,
                'chapters' => Chapter::whereIn('id', $chapterIds)
                    ->get(['id', 'title', 'version'])
                    ->map(fn ($c) => [
                        'chapter_id' => $c->id,
                        'title' => $c->title,
                        'version' => $c->version,
                    ])->all(),
                'evidence' => $evidence->map(fn ($e) => [
                    'merkle_root' => $e->merkle_root,
                    'chain_name' => $e->chain_name,
                    'tx_id' => $e->tx_id,
                    'cert_no' => $e->cert_no,
                    'status' => $e->status,
                    'confirmed_at' => $e->confirmed_at?->toIso8601String(),
                ])->all(),
                'paragraph_hits' => $paraHits->take(30)->map(fn ($p) => [
                    'chapter_title' => $p->chapter?->title ?? '—',
                    'para_no' => $p->para_no,
                    'hash' => $p->hash,
                ])->all(),
                'watermark' => $audit->match_kind === 1 && $audit->user_id ? [
                    'user_id' => $audit->user_id,
                    'chapter_id' => $chapterIds->first(),
                    'ecc_valid' => (bool) ($audit->confidence >= 0.9),
                    'confidence' => $audit->confidence >= 0.9 ? 'high' : 'low',
                ] : null,
                'suspect' => $audit->user_id ? [
                    'id' => $audit->user_id,
                    'name' => User::find($audit->user_id)?->name ?? '未知',
                ] : null,
                'confidence' => $audit->confidence >= 0.9 ? 'high'
                    : ($audit->confidence >= 0.5 ? 'medium' : 'low'),
                'operator_id' => $this->userId,
                'pirate_source' => $this->pirateSource,
            ]);

            // 4. 存储（生产 OSS disk，开发本地；磁盘名由 config 统一）
            $key = "forensic/{$export->id}-{$book->id}.pdf";
            Storage::disk(config('services.forensic.disk', 'local'))->put($key, $pdf);

            // 5. 完成 + 72h 有效期
            $export->update([
                'status' => Export::ST_DONE,
                'oss_key' => $key,
                'expires_at' => now()->addHours(72),
            ]);

            Log::info('取证包生成完成', ['export_id' => $export->id, 'audit_id' => $audit->id]);
        } catch (Throwable $e) {
            $export->update(['status' => Export::ST_FAILED]);
            throw $e;
        }
    }
}
