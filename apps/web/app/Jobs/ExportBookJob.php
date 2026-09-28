<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\EvidenceRecord;
use App\Models\Export;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * 作品一键导出（任务 11.2）：作者主权——数据与证据可携带离场。
 * 产物 ZIP：Markdown 全本 + TXT 全本 + manifest.json（章节指纹 + 存证 merkle root 引用）。
 * 异步队列 → 存储（生产 OSS / 开发本地）→ 72h 签名 URL 语义（下载端点过期即拒）。
 */
class ExportBookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $userId,
        public int $bookId,
        public string $format, // zip-md | zip-txt
    ) {}

    public function handle(): void
    {
        $book = Book::findOrFail($this->bookId);
        $export = Export::create([
            'user_id' => $this->userId,
            'book_id' => $book->id,
            'format' => $this->format,
            'status' => Export::ST_RUNNING,
        ]);

        try {
            $chapters = $book->chapters()
                ->where('status', Chapter::STATUS_PUBLISHED)
                ->with('content', 'evidenceRecords')
                ->get();

            // 1. Markdown 全本
            $md = "# {$book->title}\n\n> 授权协议：{$book->licenseLabel()}\n> 导出时间：".now()->toDateTimeString()."\n\n---\n\n";
            $txt = "{$book->title}\n\n";
            foreach ($chapters as $c) {
                $body = $c->content?->content ?? '';
                $md .= "## 第{$c->chapter_no}章 {$c->title}\n\n{$body}\n\n---\n\n";
                $txt .= "第{$c->chapter_no}章 {$c->title}\n\n{$body}\n\n\n";
            }

            // 2. 证据 manifest：每章 merkle root + 段落指纹（可离场验证）
            $manifest = [
                'book_id' => $book->id,
                'title' => $book->title,
                'license' => $book->licenseLabel(),
                'exported_at' => now()->toIso8601String(),
                'chapters' => $chapters->map(fn (Chapter $c) => [
                    'chapter_id' => $c->id,
                    'chapter_no' => $c->chapter_no,
                    'title' => $c->title,
                    'version' => $c->version,
                    'evidence' => $c->evidenceRecords->isNotEmpty()
                        ? $c->evidenceRecords->map(fn (EvidenceRecord $e) => [
                            'merkle_root' => $e->merkle_root,
                            'chain_name' => $e->chain_name,
                            'tx_id' => $e->tx_id,
                            'cert_no' => $e->cert_no,
                            'status' => $e->status,
                        ])->all()
                        : null,
                ])->all(),
            ];

            // 3. 打包
            $tmp = tempnam(sys_get_temp_dir(), 'ruoshu-export');
            $zip = new ZipArchive;
            $zip->open($tmp, ZipArchive::OVERWRITE);
            $safe = preg_replace('/[\\\\\/:*?"<>|]/u', '_', $book->title) ?? "book-{$book->id}";
            $zip->addFromString("{$safe}.md", $md);
            $zip->addFromString("{$safe}.txt", $txt);
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $zip->close();

            // 4. 存储 + 72h 有效期
            $key = "exports/{$export->id}-{$book->id}-{$this->format}.zip";
            Storage::disk(config('services.forensic.disk', 'local'))->put($key, file_get_contents($tmp) ?: '');
            @unlink($tmp);

            $export->update([
                'status' => Export::ST_DONE,
                'oss_key' => $key,
                'expires_at' => now()->addHours(72),
            ]);

            Log::info('作品导出完成', ['export_id' => $export->id, 'book_id' => $book->id]);
        } catch (Throwable $e) {
            $export->update(['status' => Export::ST_FAILED]);
            throw $e;
        }
    }
}
