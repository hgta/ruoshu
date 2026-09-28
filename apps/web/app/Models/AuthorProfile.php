<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorProfile extends Model
{
    protected $fillable = [
        'user_id', 'pen_name', 'real_name_hash', 'real_name_masked',
        'id_number_hash', 'verify_status', 'bio',
    ];

    protected function casts(): array
    {
        return ['verify_status' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public const VERIFY_NONE = 0;

    public const VERIFY_PENDING = 1;

    public const VERIFY_PASSED = 2;

    public const VERIFY_REJECTED = 3;
}
