<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Paragraph extends Model
{
    protected $fillable = [
        'chapter_id', 'book_id', 'para_no', 'version', 'hash', 'char_count',
    ];

    protected function casts(): array
    {
        return ['para_no' => 'integer', 'version' => 'integer', 'char_count' => 'integer'];
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }
}
