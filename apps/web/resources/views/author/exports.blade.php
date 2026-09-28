@extends('layouts.app')
@section('title', '作品导出 · 若书作者中心')

@section('content')
<h1>作品导出（作者主权）</h1>
<p class="hint">您的作品与版权证据随时可携带离场：导出包含全本 Markdown/TXT 与证据 manifest（章节指纹 + 存证 merkle root 引用）。导出文件 72 小时内有效，过期可重新生成。</p>

@if (session('status'))<div class="flash ok">{{ session('status') }}</div>@endif

<h2>发起导出</h2>
<form method="POST" action="{{ route('author.exports') }}" class="danmu-bar">
    @csrf
    <select name="book_id" required>
        @foreach (\App\Models\Book::where('user_id', auth()->id())->orderByDesc('id')->limit(50)->get() as $b)
            <option value="{{ $b->id }}">{{ $b->title }}</option>
        @endforeach
    </select>
    <select name="format">
        <option value="zip-md">Markdown ZIP</option>
        <option value="zip-txt">TXT ZIP</option>
    </select>
    <button class="btn" type="submit" onclick="return confirm('确认导出该作品？')">导出</button>
</form>

<h2>导出记录</h2>
<table class="table">
    <tr><th>作品 ID</th><th>格式</th><th>状态</th><th>有效期至</th><th>下载</th></tr>
    @forelse ($exports as $e)
        <tr>
            <td>#{{ $e->book_id }}</td>
            <td>{{ $e->format === 'zip-md' ? 'Markdown ZIP' : 'TXT ZIP' }}</td>
            <td>{{ [0 => '排队中', 1 => '生成中', 2 => '已完成', 3 => '失败'][$e->status] }}</td>
            <td>{{ $e->expires_at?->format('m-d H:i') ?? '—' }}</td>
            <td>
                @if ($e->status === 2 && $e->expires_at?->isFuture())
                    <a class="btn small" href="{{ url("author/exports/{$e->id}/download") }}">下载 ZIP</a>
                @elseif ($e->status === 2)
                    已过期（可重新导出）
                @else
                    —
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="5">暂无导出记录</td></tr>
    @endforelse
</table>
{{ $exports->links() }}
@endsection
