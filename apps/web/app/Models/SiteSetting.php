<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * 运营位配置（任务 12.5）：首页横幅/精选/榜单权重等免部署生效。
 * 读路径走缓存（60s），写路径主动清缓存 → 保存即生效，无需发版。
 */
class SiteSetting extends Model
{
    public const KEY_HOME = 'home'; // 首页运营位：banner/featured 等

    protected $table = 'site_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /** 读配置（带缓存），$default 兜底 */
    public static function get(string $key, array $default = []): array
    {
        return Cache::remember("settings:{$key}", 60, fn () => self::find($key)?->value ?? $default);
    }

    /** 写配置（清缓存立即生效） */
    public static function put(string $key, array $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget("settings:{$key}");
    }
}
