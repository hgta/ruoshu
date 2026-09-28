<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseChange extends Model
{
    protected $fillable = [
        'book_id', 'from_license', 'to_license', 'change_hash', 'prev_change_hash',
    ];

    protected function casts(): array
    {
        return ['from_license' => 'integer', 'to_license' => 'integer'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
