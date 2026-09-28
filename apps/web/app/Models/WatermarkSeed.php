<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatermarkSeed extends Model
{
    protected $fillable = ['book_id', 'chapter_id', 'user_id', 'payload', 'anchor_plan'];

    protected function casts(): array
    {
        return ['payload' => 'integer', 'anchor_plan' => 'array'];
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }
}
