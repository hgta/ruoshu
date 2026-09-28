@extends('layouts.app')
@section('title', '取证包 · 作者工作台')

@section('content')
<div class="author-panel">
    <h2>维权取证包</h2>
    <p class="hint">
        取证包为 PDF 报告：含溯源结论、嫌疑账号（如有）、链上存证记录与段落指纹命中清单，
        可直接用于平台投诉或法律维权。生成后 72 小时内可下载。
    </p>

    @if (session('status'))
        <div class="notice ok">{{ session('status') }}</div>
    @endif

    <h3>我的取证包</h3>
    @if ($exports->isNotEmpty())
        <table class="list">
            <thead>
            <tr><th>作品</th><th>生成时间</th><th>状态</th><th>有效期至</th><th></th></tr>
            </thead>
            <tbody>
            @foreach ($exports as $ex)
                <tr>
                    <td>{{ $ex->book->title ?? '—' }}</td>
                    <td>{{ $ex->created_at->format('Y-m-d H:i') }}</td>
                    <td>
                        @if ($ex->status === \App\Models\Export::ST_DONE)
                            <span class="badge ok">已就绪</span>
                        @elseif ($ex->status === \App\Models\Export::ST_FAILED)
                            <span class="badge bad">失败</span>
                        @else
                            <span class="badge pending">生成中…</span>
                        @endif
                    </td>
                    <td>{{ $ex->expires_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td>
                        @if ($ex->status === \App\Models\Export::ST_DONE)
                            <a href="{{ url('/author/forensics/'.$ex->id.'/download') }}" class="btn small">下载</a>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        {{ $exports->links() }}
    @else
        <p class="hint">暂无取证包。先在 <a href="{{ url('/author/trace') }}">溯源工作台</a> 完成一次溯源，再从结果页发起生成。</p>
    @endif

    @if ($audits->isNotEmpty())
        <h3>近期溯源记录</h3>
        <table class="list">
            <thead>
            <tr><th>作品</th><th>摘录</th><th>置信度</th><th>嫌疑</th><th></th></tr>
            </thead>
            <tbody>
            @foreach ($audits as $a)
                <tr>
                    <td>{{ $a->book?->title ?? '—' }}</td>
                    <td class="ellipsis">{{ \Illuminate\Support\Str::limit($a->pirate_text_excerpt, 40) }}</td>
                    <td>{{ $a->confidence >= 0.9 ? '高' : ($a->confidence >= 0.5 ? '中' : '低') }}</td>
                    <td>{{ $a->user_id ? '#'.$a->user_id : '—' }}</td>
                    <td>
                        <form method="POST" action="{{ url('/author/forensics') }}" style="display:inline">
                            @csrf
                            <input type="hidden" name="audit_id" value="{{ $a->id }}">
                            <button type="submit" class="btn small">生成取证包</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
