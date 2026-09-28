<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $fillable = [
        'actor_id', 'action', 'target_type', 'target_id',
        'before', 'after', 'note', 'actor_sig',
    ];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'target_id' => 'integer'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
