<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/**
 * 运营位管理（任务 12.5）：首页横幅/精选作品/热门标签——保存即生效（免部署）。
 * 写 site_settings + 清缓存 + 审计留痕。
 */
class SettingController extends Controller
{
    public function edit()
    {
        $home = SiteSetting::get(SiteSetting::KEY_HOME, [
            'banner_title' => '若书：每一章都有存证指纹',
            'banner_sub' => '原创不死 · 版权可证',
            'featured_book_ids' => [],
        ]);

        return view('admin.settings', ['home' => $home]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'banner_title' => ['required', 'string', 'max:60'],
            'banner_sub' => ['required', 'string', 'max:80'],
            'featured_book_ids' => ['nullable', 'string', 'max:500'],
        ]);

        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $validated['featured_book_ids']))));

        SiteSetting::put(SiteSetting::KEY_HOME, [
            'banner_title' => $validated['banner_title'],
            'banner_sub' => $validated['banner_sub'],
            'featured_book_ids' => $ids,
        ]);

        AuditLogger::log(
            $request->user(),
            'settings.home.update',
            'site_setting',
            0,
            null,
            ['banner' => $validated['banner_title'], 'featured' => $ids],
            '运营位更新（保存即生效）',
        );

        return back()->with('ok', '已保存并即时生效');
    }
}
