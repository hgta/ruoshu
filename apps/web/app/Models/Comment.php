<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Comment extends Model
{
    public const TARGET_PARAGRAPH = 'paragraph';

    public const TARGET_CHAPTER = 'chapter';

    public const TARGET_BOOK = 'book';

    public const ST_HIDDEN = 0;

    public const ST_NORMAL = 1;

    public const ST_DELETED = 2;

    protected $fillable = [
        'user_id', 'book_id', 'chapter_id', 'target_type', 'target_id',
        'parent_id', 'content', 'reply_to_user_id', 'status', 'like_count', 'reply_count',
    ];

    protected function casts(): array
    {
        return [
            'book_id' => 'integer',
            'chapter_id' => 'integer',
            'status' => 'integer',
            'like_count' => 'integer',
            'reply_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** 楼中楼回复（一层） */
    public function replies()
    {
        return $this->hasMany(self::class, 'parent_id')->whereNull('parent_id');
    }

    /** 被回复人（楼中楼内） */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reply_to_user_id');
    }
}
