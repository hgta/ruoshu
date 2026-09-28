<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Book extends Model
{
    public const STATUS_DRAFT = 0;

    public const STATUS_ONGOING = 1;

    public const STATUS_FINISHED = 2;

    public const STATUS_OFFLINE = 3;

    public const LICENSE_CC_BY_NC_ND = 1;   // 推荐默认

    public const LICENSE_CC_BY_NC = 2;

    public const LICENSE_CC_BY_ND = 3;

    public const LICENSE_ALL_RIGHTS = 4;

    public const LICENSE_CC0 = 5;

    public const LICENSE_MAP = [
        self::LICENSE_CC_BY_NC_ND => 'CC BY-NC-ND 4.0',
        self::LICENSE_CC_BY_NC => 'CC BY-NC 4.0',
        self::LICENSE_CC_BY_ND => 'CC BY-ND 4.0',
        self::LICENSE_ALL_RIGHTS => '保留所有权利',
        self::LICENSE_CC0 => 'CC0 1.0',
    ];

    /** 协议全文（任务 11.1：作品页徽章 → 协议全文链接；法务文本待 13.2 审阅） */
    public const LICENSE_FULL = [
        self::LICENSE_CC_BY_NC_ND => [
            'name' => 'CC BY-NC-ND 4.0（署名-非商业性使用-禁止演绎）',
            'text' => '本作品采用知识共享署名-非商业性使用-禁止演绎 4.0 国际许可协议授权。'
                .'您可以自由分享（复制、发行）本作品，但须遵守：署名——给出适当的署名；'
                .'非商业性使用——不得用于商业目的；禁止演绎——不得修改、转换或以本作品为基础创作。'
                .'完整协议文本见 creativecommons.org。',
        ],
        self::LICENSE_CC_BY_NC => [
            'name' => 'CC BY-NC 4.0（署名-非商业性使用）',
            'text' => '本作品采用知识共享署名-非商业性使用 4.0 国际许可协议授权。'
                .'您可自由分享与演绎本作品（含商业外的一切改编），但须署名且不得用于商业目的。'
                .'完整协议文本见 creativecommons.org。',
        ],
        self::LICENSE_CC_BY_ND => [
            'name' => 'CC BY-ND 4.0（署名-禁止演绎）',
            'text' => '本作品采用知识共享署名-禁止演绎 4.0 国际许可协议授权。'
                .'您可自由分享本作品（含商业目的），但须署名且不得修改、转换本作品。'
                .'完整协议文本见 creativecommons.org。',
        ],
        self::LICENSE_ALL_RIGHTS => [
            'name' => '保留所有权利（All Rights Reserved）',
            'text' => '本作品保留所有权利。未经作者书面许可，任何单位和个人不得以任何形式'
                .'（包括但不限于复制、转载、摘编、改编、翻译）使用本作品。'
                .'平台存证记录（含上链指纹）为版权归属的初步证明。',
        ],
        self::LICENSE_CC0 => [
            'name' => 'CC0 1.0（公共领域贡献）',
            'text' => '本作品作者在法律允许的范围内放弃全部版权，将作品贡献于公共领域。'
                .'您可以任何目的自由使用本作品，无需署名（署名仍受赞赏）。'
                .'完整协议文本见 creativecommons.org/publicdomain/zero/1.0。',
        ],
    ];

    protected $fillable = [
        'user_id', 'title', 'title_hash', 'cover', 'intro', 'category_id',
        'status', 'license', 'is_paid', 'word_count', 'chapter_count',
        'view_count', 'collect_count', 'read_count',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'license' => 'integer',
            'category_id' => 'integer',
            'is_paid' => 'boolean',
            'word_count' => 'integer',
            'chapter_count' => 'integer',
            'read_count' => 'integer',
            'collect_count' => 'integer',
            'danmu_count' => 'integer',
            'view_count' => 'integer',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('chapter_no');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'book_tags');
    }

    public function evidenceRecords(): HasMany
    {
        return $this->hasMany(EvidenceRecord::class);
    }

    public function licenseChanges(): HasMany
    {
        return $this->hasMany(LicenseChange::class);
    }

    public function scopeVisible(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_ONGOING, self::STATUS_FINISHED]);
    }

    public function licenseLabel(): string
    {
        return self::LICENSE_MAP[$this->license] ?? '';
    }
}
