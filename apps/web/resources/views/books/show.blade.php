@extends('layouts.app')
@section('title', $book->title.' · 若书')

@section('content')
<article class="book-page">
    <header class="book-head">
        @if ($book->cover)<img src="{{ $book->cover }}" alt="封面" class="cover">@endif
        <div class="book-meta">
            <h1>{{ $book->title }}</h1>
            <p class="author">作者：{{ $book->author->name }}</p>
            <p class="tags">
                {{ $book->tags->pluck('name')->implode(' · ') }}
                {{-- 授权徽章（任务 11.1）：醒目展示 + 协议全文链接 --}}
                · <a class="license-badge" href="{{ route('books.license', $book) }}"
                     title="查看授权协议全文与变更历史">{{ $book->licenseLabel() }} 🔗</a>
            </p>
            <p class="count">{{ $book->chapter_count }} 章 · {{ number_format($book->word_count) }} 字 ·
                {{ $book->status === \App\Models\Book::STATUS_FINISHED ? '已完结' : '连载中' }}</p>

            {{-- 存证徽章（版权存证差异化能力的用户界面） --}}
            @if ($evidence)
                <span class="evidence-badge" title="区块链存证可验证">
                    ✓ 已上链存证 · 编号 {{ \Str::limit($evidence->cert_no, 24) }}
                </span>
            @endif
        </div>
    </header>

    <section class="intro"><p>{{ $book->intro }}</p></section>

    <section class="toc">
        <h3>目录</h3>
        <ul>
            @forelse ($chapters as $ch)
                <li>
                    <a href="{{ route('chapters.read', $ch) }}">
                        第{{ $ch->chapter_no }}章 {{ $ch->title }}
                        @if ($ch->is_paid)<em class="vip">VIP ¥{{ number_format($ch->price / 100, 1) }}</em>@endif
                    </a>
                </li>
            @empty
                <li>暂无章节</li>
            @endforelse
        </ul>
    </section>

    <section class="reviews">
        <h3>书评</h3>
        @forelse ($reviews as $r)
            <div class="review">
                <b>{{ $r->user->name }}</b>
                <p>{{ $r->content }}</p>
            </div>
        @empty
            <p class="hint">还没有书评</p>
        @endforelse
    </section>
</article>
@endsection