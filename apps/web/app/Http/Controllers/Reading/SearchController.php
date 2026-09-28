<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reading;

use App\Http\Controllers\Controller;
use App\Services\Discovery\SearchService;
use Illuminate\Http\Request;

/** 搜索页（任务 8.4）：书名 / 作者 / 标签 */
class SearchController extends Controller
{
    public function __invoke(Request $request, SearchService $search)
    {
        $q = (string) $request->query('q', '');

        $result = $search->search($q);

        return view('search', [
            'query' => $result['query'],
            'books' => $result['books'],
            'source' => $result['source'],
        ]);
    }
}
