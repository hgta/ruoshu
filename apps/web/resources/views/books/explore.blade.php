@extends('layouts.app')
@section('title', '分类浏览 · 若书')

@section('content')
<div class="explore">
    <nav class="cat-tabs">
        <a href="{{ route('explore', array_filter(['tag' => $currentTag])) }}"
           class="{{ $currentCategory === 0 ? 'on' : '' }}">全部</a>
        @foreach ($categories as $id => $cat)
            <a href="{{ route('explore', array_filter(['category' => $id, 'tag' => $currentTag ?: null])) }}"
               class="{{ $currentCategory === $id ? 'on' : '' }}">{{ $cat['name'] }}</a>
        @endforeach
    </nav>

    <nav class="sort-tabs">
        @foreach (['new' => '最新', 'hot' => '最热', 'collect' => '收藏', 'words' => '字数'] as $k => $label)
            <a href="?{{ http_build_query(array_filter(['category' => $currentCategory ?: null, 'tag' => $currentTag ?: null, 'status' => $currentStatus ?: null, 'sort' => $k])) }}"
               class="{{ $currentSort === $k ? 'on' : '' }}">{{ $label }}</a>
        @endforeach
        <span class="divider"></span>
        <a href="?{{ http_build_query(array_filter(['category' => $currentCategory ?: null, 'tag' => $currentTag ?: null, 'status' => 'finished', 'sort' => $currentSort])) }}"
           class="{{ $currentStatus === 'finished' ? 'on' : '' }}">已完结</a>
        <a href="{{ route('search') }}" class="search-link">🔍 搜索</a>
    </nav>

    <aside class="tag-cloud">
        <span class="label">热度标签：</span>
        @forelse ($hotTags as $t)
            <a href="{{ route('explore', array_filter(['tag' => $t->id, 'category' => $currentCategory ?: null])) }}"
               class="tag {{ $currentTag === $t->id ? 'on' : '' }}">{{ $t->name }}<em>{{ $t->use_count }}</em></a>
        @empty
            <span class="hint">暂无标签</span>
        @endforelse
        @if ($currentTag)
            <a href="{{ route('explore', ['category' => $currentCategory ?: null]) }}" class="tag clear">✕ 清除标签</a>
        @endif
    </aside>

    <div class="book-grid">
        @forelse ($books as $b)
            <a href="{{ route('books.show', $b) }}" class="card">
                @if ($b->cover)<img src="{{ $b->cover }}" alt="">{{ @endif
                <h4>{{ $b->title }}</h4>
                <p>{{ $b->author->name }}</p>
                <p class="hint">{{ \Illuminate\Support\Str::limit($b->intro, 50) }}</p>
                <p class="meta">{{ number_format($b->word_count) }} 字 · {{ $b->status === 2 ? '已完结' : '连载中' }}</p>
            </a>
        @empty
            <p>这个筛选组合下还没有作品，等你来写第一部</p>
        @endforelse
    </div>

    {{ $books->links() }}
</div>
@endsection
