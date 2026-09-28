@extends('layouts.app')
@section('title', $book->title.' · 授权协议')

@section('content')
<article class="license-page">
    <header>
        <h1>《{{ $book->title }}》授权信息</h1>
        <p class="hint">
            当前授权：<a href="{{ route('books.show', $book) }}">返回作品页</a> ·
            <a href="{{ route('books.rss', $book) }}">RSS 订阅</a>
        </p>
    </header>

    <section class="license-card">
        <h3>{{ $license['name'] }}</h3>
        <p>{{ $license['text'] }}</p>
        <p class="hint">协议文本为平台摘要版，正式文本以法务审定版（任务 13.2）为准。</p>
    </section>

    <section class="license-history">
        <h3>授权变更历史（链式留痕）</h3>
        <ul>
            @forelse ($changes as $change)
                <li>
                    <time>{{ $change->created_at->format('Y-m-d H:i') }}</time>
                    {{ \App\Models\Book::LICENSE_MAP[$change->from_license] ?? '初始' }}
                    → {{ \App\Models\Book::LICENSE_MAP[$change->to_license] ?? '' }}
                    <span class="mono hint">{{ \Str::limit($change->change_hash, 20) }}</span>
                </li>
            @empty
                <li>授权自作品创建起未变更</li>
            @endforelse
        </ul>
        <p class="hint">变更记录使用链式哈希连接（每条引用前一条哈希），历史不可抵赖。</p>
    </section>
</article>
@endsection
