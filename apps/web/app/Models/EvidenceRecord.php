<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceRecord extends Model
{
    public const KIND_CHAPTER = 1;      // 付费书逐章

    public const KIND_DAILY_BATCH = 2;  // 免费书日批

    public const KIND_STATEMENT = 3;    // 月度对账单

    public const KIND_FORENSIC = 4;     // 取证包

    public const ST_PENDING = 0;

    public const ST_SUBMITTED = 1;

    public const ST_CONFIRMED = 2;

    public const ST_RETRYING = 3;

    public const ST_DEAD = 4;

    protected $fillable = [
        'book_id', 'chapter_id', 'kind', 'title_hash', 'merkle_root',
        'word_count', 'prev_chapter_root', 'version', 'status',
        'chain_name', 'tx_id', 'cert_no', 'retry_count', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'kind' => 'integer',
            'version' => 'integer',
            'status' => 'integer',
            'retry_count' => 'integer',
            'payload' => 'array',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }
}
