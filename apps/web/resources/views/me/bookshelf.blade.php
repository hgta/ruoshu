@extends('layouts.app')
@section('title', '我的书架 · 若书')

@section('content')
<div class="bookshelf">
    <h2>我的书架</h2>

    @forelse ($shelf as $row)
        <a href="{{ route('books.show', $row->book) }}" class="shelf-row">
            @if ($row->book->cover)<img src="{{ $row->book->cover }}" alt="">{{ @endif
            <div>
                <b>{{ $row->book->title }}</b>
                <p class="hint">读到第 {{ $row->last_read_para_no }} 段 ·
                    {{ $row->last_read_at?->diffForHumans() ?? '还没开始读' }}</p>
            </div>
        </a>
    @empty
        <p class="hint">书架是空的，去<a href="{{ route('explore') }}">发现</a>好书</p>
    @endforelse

    {{ $shelf->links() }}
</div>
@endsection