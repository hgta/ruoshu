<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyStatement extends Model
{
    public const ST_RUNNING = 0;

    public const ST_DONE = 1;

    public const ST_FAILED = 2;

    protected $table = 'monthly_statements';

    protected $fillable = [
        'user_id', 'period', 'gross', 'fee', 'net', 'entry_count',
        'pdf_path', 'tsa_token', 'tsa_source', 'tsa_time', 'status',
    ];

    protected function casts(): array
    {
        return [
            'gross' => 'integer',
            'fee' => 'integer',
            'net' => 'integer',
            'entry_count' => 'integer',
            'tsa_time' => 'datetime',
            'status' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
