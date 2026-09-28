<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    public const SOURCE_DONATION = 'donation';

    public const SOURCE_PURCHASE = 'purchase';

    public const SOURCE_SUBSCRIPTION = 'subscription';

    protected $fillable = [
        'user_id', 'book_id', 'chapter_id', 'source_type', 'source_id',
        'gross', 'fee_rate', 'fee', 'net', 'entry_hash', 'prev_hash',
        'merkle_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'gross' => 'integer',
            'fee_rate' => 'float',
            'fee' => 'integer',
            'net' => 'integer',
        ];
    }

    /**
     * 计算链式哈希：SHA-256(关键字段 + prev_hash)。
     * 唯一真源要求：与来源记录同事务写入（见 LedgerService::record）。
     */
    public static function computeHash(
        int $userId,
        int $bookId,
        string $sourceType,
        int $sourceId,
        int $gross,
        int $fee,
        int $net,
        ?string $prevHash,
    ): string {
        return hash('sha256', implode('|', [
            $userId, $bookId, $sourceType, $sourceId,
            $gross, $fee, $net, $prevHash ?? '',
        ]));
    }

    public function scopeForBook(Builder $q, int $bookId): Builder
    {
        return $q->where('book_id', $bookId);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
