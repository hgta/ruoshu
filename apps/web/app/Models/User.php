<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLE_READER = 1;

    public const ROLE_AUTHOR = 2;

    public const ROLE_ADMIN = 4;

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'avatar', 'roles', 'status',
        'wechat_openid', 'wechat_unionid',
    ];

    protected $hidden = ['password', 'remember_token', 'wechat_openid', 'wechat_unionid'];

    protected function casts(): array
    {
        return [
            'roles' => 'integer',
            'status' => 'integer',
            'password' => 'hashed',
        ];
    }

    public function isAuthor(): bool
    {
        return (bool) ($this->roles & self::ROLE_AUTHOR);
    }

    public function isAdmin(): bool
    {
        return (bool) ($this->roles & self::ROLE_ADMIN);
    }

    public function grantRole(int $role): void
    {
        $this->roles |= $role;
        $this->save();
    }

    public function authorProfile(): HasOne
    {
        return $this->hasOne(AuthorProfile::class);
    }

    public function readerProfile(): HasOne
    {
        return $this->hasOne(ReaderProfile::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(ChapterPurchase::class);
    }

    /** 生成自动昵称（微信登录无输入时） */
    public static function generateNickname(): string
    {
        return '书友'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }
}
