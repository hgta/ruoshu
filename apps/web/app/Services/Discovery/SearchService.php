<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Models\Book;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Meilisearch\Client;

/**
 * 搜索服务（任务 8.2 / 8.4）：
 *  - 主路：Meilisearch（元数据索引：title/author/tags；队列同步 ≤30s 生效）
 *  - 降级：Meilisearch 不可用时 MySQL LIKE 容错搜索（不挡用户）
 * 结果统一结构，调用方无感。
 */
class SearchService
{
    public function __construct(
        private readonly ?Client $meili = null,
    ) {}

    /** 全文搜索：书名 / 作者名 / 标签名 */
    public function search(string $q, int $limit = 20): array
    {
        $q = trim(mb_substr($q, 0, 64));
        if ($q === '') {
            return ['books' => collect(), 'source' => 'none', 'query' => ''];
        }

        try {
            return ['books' => $this->searchViaMeili($q, $limit), 'source' => 'meilisearch', 'query' => $q];
        } catch (\Throwable $e) {
            Log::warning('Meilisearch 不可用，降级 LIKE 搜索', ['err' => $e->getMessage()]);

            return ['books' => $this->searchViaSql($q, $limit), 'source' => 'fallback-sql', 'query' => $q];
        }
    }

    /** 主路：Meilisearch（书名 + 作者 + 标签统一索引 books 索引） */
    private function searchViaMeili(string $q, int $limit): Collection
    {
        $hits = $this->meili->index('books')
            ->search($q, [
                'limit' => $limit,
                'filter' => 'status IN [1, 2]',
                'attributesToRetrieve' => ['id', 'title', 'intro', 'category_id', 'word_count', 'author_name', 'tags'],
            ])->getHits();

        $ids = array_column($hits, 'id');
        if ($ids === []) {
            return collect();
        }

        // 回源 DB 保字段完整 + 保持相关性顺序
        $order = array_flip($ids);

        return Book::visible()
            ->whereIn('id', $ids)
            ->with('author:id,name,avatar')
            ->get()
            ->sortBy(fn (Book $b) => $order[$b->id] ?? 999);
    }

    /** 降级路：MySQL LIKE（书名 / 作者名 / 标签名三路 UNION 语义） */
    private function searchViaSql(string $q, int $limit): Collection
    {
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';

        $authorIds = User::where('name', 'like', $like)->pluck('id');
        $tagBookIds = \DB::table('book_tags')
            ->whereIn('tag_id', Tag::where('name', 'like', $like)->pluck('id'))
            ->pluck('book_id');

        return Book::visible()
            ->where(fn ($query) => $query
                ->where('title', 'like', $like)
                ->orWhereIn('user_id', $authorIds)
                ->orWhereIn('id', $tagBookIds))
            ->with('author:id,name,avatar')
            ->orderByDesc('view_count')
            ->limit($limit)
            ->get();
    }
}
