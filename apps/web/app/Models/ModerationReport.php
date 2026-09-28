<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModerationReport extends Model
{
    public const TARGET_COMMENT = 'comment';

    public const TARGET_DANMU = 'danmu';

    public const TARGET_BOOK = 'book';

    public const KIND_SPAM = 0;

    public const KIND_PORN = 1;

    public const KIND_INFRINGEMENT = 2;

    public const KIND_DMCA = 3;

    public const KIND_OTHER = 4;

    public const KIND_MAP = [
        self::KIND_SPAM => '垃圾信息',
        self::KIND_PORN => '色情低俗',
        self::KIND_INFRINGEMENT => '侵权',
        self::KIND_DMCA => 'DMCA 下架请求',
        self::KIND_OTHER => '其他',
    ];

    public const ST_PENDING = 0;

    public const ST_HANDLED = 1;

    public const ST_DISMISSED = 2;

    public const ACTION_HIDE = 'hide';

    public const ACTION_OFFLINE = 'offline';

    public const ACTION_LEGAL = 'legal';

    public const ACTION_NONE = 'none';

    protected $fillable = [
        'reporter_id', 'target_type', 'target_id', 'reason_kind', 'reason_text',
        'status', 'handler_id', 'action', 'handle_note', 'handled_at',
    ];

    protected function casts(): array
    {
        return [
            'reason_kind' => 'integer',
            'status' => 'integer',
            'handled_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handler_id');
    }
}
