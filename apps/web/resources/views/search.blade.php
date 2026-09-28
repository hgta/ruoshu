@extends('layouts.app')
@section('title', '搜索 · 若书')

@section('content')
<div class="search-page">
    <form method="GET" action="{{ route('search') }}" class="search-bar">
        <input type="search" name="q" value="{{ $query }}" placeholder="书名 / 作者 / 标签…" autofocus
               maxlength="64" required>
        <button type="submit" class="btn">搜索</button>
    </form>

    @if ($query !== '')
        <p class="hint">
            「{{ $query }}」共 {{ $books->count() }} 个结果
            @if ($source === 'fallback-sql')
                ·（搜索引擎暂不可用，已切换基础模式）
            @endif
        </p>

        <div class="book-grid">
            @forelse ($books as $b)
                <a href="{{ route('books.show', $b) }}" class="card">
                    @if ($b->cover)<img src="{{ $b->cover }}" alt="">{{ @endif
                    <h4>{{ $b->title }}</h4>
                    <p>{{ $b->author->name }}</p>
                    <p class="hint">{{ \Illuminate\Support\Str::limit($b->intro, 50) }}</p>
                </a>
            @empty
                <p>没有找到「{{ $query }}」相关的作品。换个关键词试试？</p>
            @endforelse
        </div>
    @endif
</div>
@endsection
