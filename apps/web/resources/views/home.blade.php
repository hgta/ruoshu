@extends('layouts.app')
@section('content')
<section class="home-hero">
    <h1>作品归作者所有</h1>
    <p>逐章区块链存证 · 隐形水印溯源 · 高分成透明到账 · 数据随时带走</p>
    <form method="GET" action="{{ route('search') }}" class="hero-search">
        <input type="search" name="q" placeholder="搜索书名 / 作者 / 标签…" maxlength="64">
        <button type="submit" class="btn">找书</button>
    </form>
</section>

{{-- 精选横幅 --}}
@if ($featured->isNotEmpty())
    <section class="home-banner">
        @foreach ($featured as $b)
            <a href="{{ route('books.show', $b) }}" class="banner-item">
                @if ($b->cover)<img src="{{ $b->cover }}" alt="">{{ @endif
                <div>
                    <h3>{{ $b->title }}</h3>
                    <p>{{ $b->author->name }} · {{ number_format($b->word_count) }} 字</p>
                </div>
            </a>
        @endforeach
    </section>
@endif

<div class="home-columns">
    <div class="home-main">
        {{-- 榜单 Tab --}}
        <section class="module">
            <h3>榜单</h3>
            <nav class="rank-tabs">
                <button data-tab="hot" class="on">最热</button>
                <button data-tab="new">新书</button>
                <button data-tab="collect">收藏</button>
            </nav>
            @foreach ($rankings as $key => $list)
                <ol class="rank-list {{ $loop->first ? '' : 'hide' }}" data-panel="{{ $key }}">
                    @forelse ($list as $i => $b)
                        <li>
                            <em class="{{ $i < 3 ? 'top'.($i + 1) : '' }}">{{ $i + 1 }}</em>
                            <a href="{{ route('books.show', $b) }}">{{ $b->title }}</a>
                        </li>
                    @empty
                        <li class="hint">虚位以待</li>
                    @endforelse
                </ol>
            @endforeach
        </section>

        {{-- 新书速递 --}}
        <section class="module">
            <h3>新书速递</h3>
            <div class="book-row">
                @forelse ($fresh as $b)
                    <a href="{{ route('books.show', $b) }}" class="card small">
                        @if ($b->cover)<img src="{{ $b->cover }}" alt="">{{ @endif
                        <h4>{{ $b->title }}</h4>
                        <p>{{ $b->author->name }}</p>
                    </a>
                @empty
                    <p class="hint">还没有新作发布，<a href="{{ route('login') }}">成为第一个作者 →</a></p>
                @endforelse
            </div>
        </section>

        {{-- 分类宫格 --}}
        <section class="module">
            <h3>分类</h3>
            <div class="cat-grid">
                @foreach ($categories as $id => $cat)
                    <a href="{{ route('explore', ['category' => $id]) }}">
                        <b>{{ $cat['name'] }}</b>
                        <span>{{ collect($cat['sub'])->take(3)->implode(' / ') }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    </div>

    <aside class="home-side">
        {{-- 热度标签 --}}
        <section class="module">
            <h3>热度标签</h3>
            <div class="tag-cloud">
                @forelse ($hotTags as $t)
                    <a href="{{ route('explore', ['tag' => $t->id]) }}" class="tag">{{ $t->name }}</a>
                @empty
                    <p class="hint">暂无</p>
                @endforelse
            </div>
        </section>

        {{-- 打赏动态流 --}}
        <section class="module">
            <h3>打赏动态</h3>
            <ul class="donation-feed">
                @forelse ($donations as $d)
                    <li>
                        <b>{{ $d->user->name }}</b>
                        @if ($d->gift)
                            打赏了 {{ $d->gift->name }}
                        @else
                            打赏了 ¥{{ number_format($d->amount / 100, 2) }}
                        @endif
                        给 <a href="{{ route('books.show', $d->book) }}">《{{ $d->book->title }}》</a>
                    </li>
                @empty
                    <li class="hint">还没有打赏记录</li>
                @endforelse
            </ul>
        </section>
    </aside>
</div>

<script>
    document.querySelectorAll('.rank-tabs button').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.rank-tabs button').forEach(function (b) { b.classList.remove('on'); });
            btn.classList.add('on');
            document.querySelectorAll('.rank-list').forEach(function (ol) {
                ol.classList.toggle('hide', ol.dataset.panel !== btn.dataset.tab);
            });
        });
    });
</script>
@endsection
