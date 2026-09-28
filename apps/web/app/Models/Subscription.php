<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    public const ST_UNPAID = 0;

    public const ST_ACTIVE = 1;

    public const ST_EXPIRED = 2;

    public const ST_CANCELLED = 3;

    protected $fillable = [
        'user_id', 'book_id', 'price', 'pay_channel', 'pay_trade_no',
        'status', 'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'status' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ST_ACTIVE
            && $this->ends_at !== null
            && $this->ends_at->isFuture();
    }
}
