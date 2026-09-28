<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Gift;
use Illuminate\Database\Seeder;

class GiftSeeder extends Seeder
{
    /**
     * 礼物体系（打赏为主商业模式的仪式感设计，女频吃这套）。
     * 定价梯度：1 / 5 / 10 / 50 / 100 / 520 元。
     */
    public function run(): void
    {
        $gifts = [
            ['name' => '小星星', 'price' => 100, 'sort' => 1],
            ['name' => '棒棒糖', 'price' => 500, 'sort' => 2],
            ['name' => '海洋之心', 'price' => 1000, 'sort' => 3],
            ['name' => '月光宝盒', 'price' => 5000, 'sort' => 4],
            ['name' => '梦幻城堡', 'price' => 10000, 'sort' => 5],
            ['name' => '星河宇宙', 'price' => 52000, 'sort' => 6],
        ];

        foreach ($gifts as $g) {
            Gift::updateOrCreate(['name' => $g['name']], $g + ['icon' => 'gifts/'.md5($g['name']).'.png', 'enabled' => true]);
        }
    }
}
