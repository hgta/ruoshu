<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Chapter extends Model
{
    public const STATUS_DRAFT = 0;

    public const STATUS_PUBLISHED = 1;

    public const STATUS_OFFLINE = 2;

    protected $fillable = [
        'book_id', 'chapter_no', 'title', 'word_count',
        'status', 'is_paid', 'price', 'version', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'chapter_no' => 'integer',
            'word_count' => 'integer',
            'status' => 'integer',
            'is_paid' => 'boolean',
            'price' => 'integer',
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function content(): HasOne
    {
        return $this->hasOne(ChapterContent::class);
    }

    public function paragraphs(): HasMany
    {
        return $this->hasMany(Paragraph::class)->orderBy('para_no');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(ChapterPurchase::class);
    }

    public function evidenceRecords(): HasMany
    {
        return $this->hasMany(EvidenceRecord::class);
    }
}
