<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Export extends Model
{
    public const ST_QUEUED = 0;

    public const ST_RUNNING = 1;

    public const ST_DONE = 2;

    public const ST_FAILED = 3;

    protected $fillable = ['user_id', 'book_id', 'format', 'status', 'oss_key', 'expires_at'];

    protected function casts(): array
    {
        return ['status' => 'integer', 'expires_at' => 'datetime'];
    }
}
