<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reading;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\User;
use Illuminate\Http\Response;

/**
 * 订阅输出（任务 11.3）：作品 RSS 2.0 / 作者 Atom 1.0。
 * 红线：付费正文永不出现在 feed——付费章节只给标题 + 引导语，摘要仅取免费章正文前 200 字。
 */
class FeedController extends Controller
{
    private const SUMMARY_LEN = 200;

    /** 作品 RSS：item = 已发布章节（最新 50，升序日期排序要求由阅读器处理） */
    public function bookRss(Book $book): Response
    {
        abort_unless(in_array($book->status, [Book::STATUS_ONGOING, Book::STATUS_FINISHED]), 404);

        $chapters = $book->chapters()
            ->where('status', Chapter::STATUS_PUBLISHED)
            ->latest('published_at')
            ->limit(50)
            ->get(['id', 'chapter_no', 'title', 'is_paid', 'published_at']);

        $items = $chapters->map(fn (Chapter $c) => $this->itemXml($c))->implode("\n");

        $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
        <channel>
          <title>{$this->esc($book->title)}</title>
          <link>{$this->link("/books/{$book->id}")}</link>
          <description>{$this->esc(mb_substr($book->intro, 0, self::SUMMARY_LEN))}</description>
          <atom:link href="{$this->link("/books/{$book->id}/rss")}" rel="self"/>
        {$items}
        </channel>
        </rss>
        XML;

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    /** 作者 Atom：feed = 作者全部可见书的最新章节 */
    public function authorAtom(User $author): Response
    {
        $bookIds = Book::where('user_id', $author->id)->visible()->pluck('id');

        abort_if($bookIds->isEmpty(), 404, '该作者暂无可订阅作品');

        $chapters = Chapter::whereIn('book_id', $bookIds)
            ->where('status', Chapter::STATUS_PUBLISHED)
            ->with('book:id,title')
            ->latest('published_at')
            ->limit(50)
            ->get();

        $entries = $chapters->map(fn (Chapter $c) => <<<XML
            <entry>
              <title>{$this->esc("《{$c->book->title}》{$c->title}")}</title>
              <link href="{$this->link("/read/{$c->id}")}"/>
              <id>urn:ruoshu:chapter:{$c->id}</id>
              <updated>{$c->published_at?->toRfc3339String()}</updated>
              <summary>{$this->esc($this->summaryOf($c))}</summary>
            </entry>
        XML)->implode("\n");

        $updated = $chapters->first()?->published_at?->toRfc3339String() ?? now()->toRfc3339String();

        $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <feed xmlns="http://www.w3.org/2005/Atom">
          <title>{$this->esc($author->name.' 的作品更新')}</title>
          <link href="{$this->link("/authors/{$author->id}/atom")}" rel="self"/>
          <id>urn:ruoshu:author:{$author->id}</id>
          <updated>{$updated}</updated>
        {$entries}
        </feed>
        XML;

        return response($xml, 200, ['Content-Type' => 'application/atom+xml; charset=UTF-8']);
    }

    // ===== 内部 =====

    private function itemXml(Chapter $c): string
    {
        return <<<XML
          <item>
            <title>{$this->esc("第{$c->chapter_no}章 {$c->title}")}</title>
            <link>{$this->link("/read/{$c->id}")}</link>
            <guid>urn:ruoshu:chapter:{$c->id}</guid>
            <pubDate>{$c->published_at?->toRfc2822String()}</pubDate>
            <description>{$this->esc($this->summaryOf($c))}</description>
          </item>
        XML;
    }

    /** 摘要：免费章取正文前 200 字；付费章绝不出现正文 */
    private function summaryOf(Chapter $c): string
    {
        if ($c->is_paid) {
            return '本章为 VIP 章节，请前往若书平台解锁阅读（付费正文不会出现在订阅源中）。';
        }

        $content = $c->content?->content ?? '';

        return mb_substr(preg_replace('/\s+/u', ' ', $content) ?? '', 0, self::SUMMARY_LEN);
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function link(string $path): string
    {
        return rtrim(config('app.url'), '/').$path;
    }
}
