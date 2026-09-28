<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChapterPurchase extends Model
{
    public const ST_UNPAID = 0;

    public const ST_PAID = 1;

    protected $fillable = [
        'user_id', 'book_id', 'chapter_id', 'price',
        'pay_channel', 'pay_trade_no', 'status',
    ];

    protected function casts(): array
    {
        return ['price' => 'integer', 'status' => 'integer'];
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }
}
