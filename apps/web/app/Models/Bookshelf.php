<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bookshelf extends Model
{
    protected $fillable = [
        'user_id', 'book_id', 'last_read_chapter_id',
        'last_read_para_no', 'last_read_offset',
    ];

    protected function casts(): array
    {
        return [
            'last_read_chapter_id' => 'integer',
            'last_read_para_no' => 'integer',
            'last_read_offset' => 'integer',
            'last_read_at' => 'datetime',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
