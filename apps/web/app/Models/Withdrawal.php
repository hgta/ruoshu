<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Withdrawal extends Model
{
    public const ST_PENDING = 0;

    public const ST_APPROVED = 1;

    public const ST_PAID = 2;

    public const ST_REJECTED = 3;

    protected $fillable = [
        'user_id', 'amount', 'trade_no', 'status',
        'review_note', 'reviewer_id', 'reviewed_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => 'integer',
            'reviewed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
