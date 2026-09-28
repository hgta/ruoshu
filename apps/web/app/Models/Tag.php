<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    protected $fillable = ['name', 'use_count'];

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class);
    }
}
