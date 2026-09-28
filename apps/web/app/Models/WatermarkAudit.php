<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WatermarkAudit extends Model
{
    protected $fillable = [
        'user_id', 'book_id', 'pirate_text_excerpt', 'pirate_source',
        'confidence', 'match_kind', 'status', 'evidence_record_id',
    ];

    protected function casts(): array
    {
        return ['confidence' => 'float', 'match_kind' => 'integer', 'status' => 'integer'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function suspect(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
