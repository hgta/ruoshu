<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Donation extends Model
{
    public const ST_UNPAID = 0;

    public const ST_PAID = 1;

    public const ST_FAILED = 2;

    protected $fillable = [
        'user_id', 'book_id', 'chapter_id', 'gift_id', 'amount',
        'pay_channel', 'pay_trade_no', 'status',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'status' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function gift(): BelongsTo
    {
        return $this->belongsTo(Gift::class);
    }
}
