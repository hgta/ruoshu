<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReaderProfile extends Model
{
    protected $fillable = ['user_id', 'coin_balance', 'total_donated'];

    protected function casts(): array
    {
        return ['coin_balance' => 'integer', 'total_donated' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
