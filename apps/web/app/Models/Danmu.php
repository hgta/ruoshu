<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Danmu extends Model
{
    public const ST_HIDDEN = 0;

    public const ST_NORMAL = 1;

    protected $table = 'danmu';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected $fillable = [
        'user_id', 'book_id', 'chapter_id', 'paragraph_no', 'content',
        'status', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'paragraph_no' => 'integer',
            'status' => 'integer',
            'archived_at' => 'datetime',
        ];
    }
}
