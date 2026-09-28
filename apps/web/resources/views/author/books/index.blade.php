@extends('layouts.app')
@section('title', '我的作品 · 若书')

@section('content')
<div class="author-books">
    <h2>我的作品</h2>
    <a href="{{ route('author.books.create') }}" class="btn">+ 开新书</a>

    <table class="book-table">
        <tr><th>书名</th><th>状态</th><th>章节</th><th>字数</th><th>授权</th><th>操作</th></tr>
        @forelse ($books as $b)
            <tr>
                <td><a href="{{ route('books.show', $b) }}">{{ $b->title }}</a></td>
                <td>{{ [\App\Models\Book::STATUS_DRAFT => '草稿', \App\Models\Book::STATUS_ONGOING => '连载',
                    \App\Models\Book::STATUS_FINISHED => '完结', \App\Models\Book::STATUS_OFFLINE => '下架'][$b->status] }}</td>
                <td>{{ $b->chapter_count }}</td>
                <td>{{ number_format($b->word_count) }}</td>
                <td>{{ \App\Models\Book::LICENSE_MAP[$b->license] }}</td>
                <td>
                    <a href="/author/books/{{ $b->id }}/chapters">章节</a>
                    <a href="/author/books/{{ $b->id }}/edit">设置</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="6">还没有作品，开一本新书吧</td></tr>
        @endforelse
    </table>
</div>
@endsection