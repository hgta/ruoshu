@extends('layouts.app')
@section('title', '章节管理 · '.$book->title)

@section('content')
<div class="chapter-list">
    <h2>《{{ $book->title }}》章节</h2>
    <a href="/author/books/{{ $book->id }}/chapters/edit" class="btn">+ 写新章</a>

    <table class="book-table">
        <tr><th>#</th><th>标题</th><th>字数</th><th>状态</th><th>VIP</th><th>操作</th></tr>
        @forelse ($chapters as $ch)
            <tr>
                <td>{{ $ch->chapter_no }}</td>
                <td>{{ $ch->title }}</td>
                <td>{{ number_format($ch->word_count) }}</td>
                <td>{{ $ch->status === \App\Models\Chapter::STATUS_PUBLISHED ? '已发布' : '草稿' }}</td>
                <td>{{ $ch->is_paid ? '¥'.number_format($ch->price / 100, 1) : '免费' }}</td>
                <td>
                    <a href="/author/books/{{ $book->id }}/chapters/edit/{{ $ch->id }}">编辑</a>
                    @if ($ch->status !== \App\Models\Chapter::STATUS_PUBLISHED)
                        <a href="{{ route('chapters.read', $ch) }}">预览</a>
                    @else
                        <a href="{{ route('chapters.read', $ch) }}">查看</a>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6">还没有章节</td></tr>
        @endforelse
    </table>
</div>
@endsection