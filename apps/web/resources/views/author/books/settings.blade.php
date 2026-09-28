@extends('layouts.app')
@section('title', '作品设置 · '.$book->title)

@section('content')
<div class="book-settings">
    <h2>《{{ $book->title }}》设置</h2>

    <form method="POST" action="/author/books/{{ $book->id }}">
        @csrf @method('PUT')
        <label>版权授权
            <select name="license">
                @foreach ($licenseOptions as $id => $label)
                    <option value="{{ $id }}" @selected($id === $book->license)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label>作品状态
            <select name="status">
                <option value="{{ \App\Models\Book::STATUS_ONGOING }}" @selected($book->status === \App\Models\Book::STATUS_ONGOING)>连载中</option>
                <option value="{{ \App\Models\Book::STATUS_FINISHED }}" @selected($book->status === \App\Models\Book::STATUS_FINISHED)>完结</option>
                <option value="{{ \App\Models\Book::STATUS_OFFLINE }}" @selected($book->status === \App\Models\Book::STATUS_OFFLINE)>下架（读者侧下线，存证记录保留）</option>
            </select>
        </label>

        <button type="submit">保存设置</button>
    </form>

    <h3>授权变更历史</h3>
    <ul class="license-history">
        @forelse ($book->licenseChanges()->latest()->limit(20)->get() as $lc)
            <li>{{ $lc->created_at->format('Y-m-d H:i') }} :
                {{ $lc->from_license === 0 ? '创建' : \App\Models\Book::LICENSE_MAP[$lc->from_license] }}
                → {{ \App\Models\Book::LICENSE_MAP[$lc->to_license] }}</li>
        @empty
            <li>无变更记录</li>
        @endforelse
    </ul>
</div>
@endsection