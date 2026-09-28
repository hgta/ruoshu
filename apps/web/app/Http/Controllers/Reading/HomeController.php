<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reading;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Donation;
use App\Models\SiteSetting;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * 首页（任务 8.3）：精选横幅 / 新书速递 / 榜单 Tab / 分类宫格 / 热度标签 / 打赏动态流。
 * 热区块 5 分钟缓存（首页读多写少）。
 */
class HomeController extends Controller
{
    public function __invoke(Request $request)
    {
        // 运营位配置（任务 12.5：后台保存即生效；配置 60s 读缓存 + 模块 5 分钟缓存 → 生效延迟 ≤5 分钟，免部署）
        $settings = SiteSetting::get(SiteSetting::KEY_HOME, []);

        $m = Cache::remember('home:modules', now()->addMinutes(5), fn () => [
            // 精选横幅：运营位指定的 book_id 优先，空/失效则回退阅读量 Top5
            'featured' => ! empty($settings['featured_book_ids'])
                ? Book::visible()->whereIn('id', $settings['featured_book_ids'])
                    ->with('author:id,name')->limit(5)->get()
                : Book::visible()->orderByDesc('view_count')
                    ->with('author:id,name')->limit(5)->get(),
            // 新书速递
            'fresh' => Book::visible()->latest('created_at')
                ->with('author:id,name')->limit(6)->get(),
            // 热度标签
            'hotTags' => Tag::orderByDesc('use_count')->limit(15)->get(),
            // 打赏动态流（最新已支付打赏：读者 → 礼物 → 作品）
            'donations' => Donation::where('status', Donation::ST_PAID)
                ->with(['user:id,name', 'gift:id,name,icon', 'book:id,title'])
                ->latest('id')->limit(10)->get(),
        ]);

        // 榜单 Tab（hot/new/collect 三个维度）
        $rankings = Cache::remember('home:rankings', now()->addMinutes(5), fn () => [
            'hot' => Book::visible()->orderByDesc('view_count')->limit(10)->get(['id', 'title', 'view_count']),
            'new' => Book::visible()->latest('created_at')->limit(10)->get(['id', 'title', 'created_at']),
            'collect' => Book::visible()->orderByDesc('collect_count')->limit(10)->get(['id', 'title', 'collect_count']),
        ]);

        return view('home', [
            'featured' => $m['featured'],
            'fresh' => $m['fresh'],
            'rankings' => $rankings,
            'hotTags' => $m['hotTags'],
            'donations' => $m['donations'],
            'categories' => config('categories'),
        ]);
    }
}
