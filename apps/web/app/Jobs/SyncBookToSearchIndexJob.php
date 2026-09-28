<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Book;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Meilisearch\Client;

/**
 * 搜索索引同步（任务 8.2）：作品发布/更新/下架后投递，队列异步 ≤30s 生效。
 * Meilisearch 不可用时重试，最终失败仅告警不丢主数据（搜索可降级 LIKE）。
 */
class SyncBookToSearchIndexJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int,int> */
    public array $backoff = [10, 30, 60, 300];

    public function __construct(public int $bookId) {}

    public function handle(): void
    {
        // 容错（红线：索引失败绝不影响主流程——MySQL 是唯一真源，索引可由下次变更/重建修复）
        try {
            $this->sync();
        } catch (\Throwable $e) {
            if ($this->attempts() < $this->tries) {
                // 退避重试
                $this->release($this->backoff[$this->attempts() - 1] ?? 60);

                return;
            }
            // 最终失败：仅告警，主数据不受影响（搜索自动降级 LIKE）
            Log::warning('搜索索引同步最终失败（主数据不受影响）', [
                'book_id' => $this->bookId, 'err' => $e->getMessage(),
            ]);
        }
    }

    private function sync(): void
    {
        $book = Book::with(['author:id,name', 'tags:id,name'])->find($this->bookId);

        $meili = app(Client::class);
        $index = $meili->index('books');

        if ($book === null || $book->status === Book::STATUS_OFFLINE || $book->status === Book::STATUS_DRAFT) {
            $index->deleteDocument($this->bookId); // 下架/草稿移出索引

            return;
        }

        $index->addOrUpdateDocuments([[
            'id' => $book->id,
            'title' => $book->title,
            'intro' => mb_substr((string) $book->intro, 0, 200),
            'category_id' => $book->category_id,
            'status' => $book->status,
            'word_count' => $book->word_count,
            'view_count' => $book->view_count,
            'is_paid' => $book->is_paid,
            'author_name' => $book->author?->name ?? '',
            'tags' => $book->tags->pluck('name')->all(),
            'updated_at' => $book->updated_at?->toIso8601String(),
        ]], 'id');

        Log::debug('搜索索引已同步', ['book' => $this->bookId]);
    }
}
