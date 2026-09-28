@extends('layouts.app')
@section('title', '审核队列 · 管理后台')

@section('content')
<h1>审核队列（待处理举报）</h1>
@if (session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif

<table class="table">
    <tr><th>时间</th><th>类型</th><th>目标内容</th><th>举报人</th><th>操作</th></tr>
    @forelse ($reports as $r)
        <tr>
            <td>{{ $r->created_at->format('m-d H:i') }}</td>
            <td>
                <b>{{ \App\Models\ModerationReport::KIND_MAP[$r->reason_kind] }}</b>
                @if ($r->reason_kind === \App\Models\ModerationReport::KIND_DMCA)
                    <a class="btn small" href="{{ route('admin.moderation.show', $r) }}">证据面板</a>
                @endif
            </td>
            <td title="{{ $r->reason_text }}">
                {{ \Str::limit($targets[$r->id] ?? '—', 60) }}
            </td>
            <td>{{ $r->reporter?->name }}（#{{ $r->reporter_id }}）</td>
            <td>
                <form method="POST" action="{{ url("admin/moderation/{$r->id}/handle") }}" class="inline handle-form">
                    @csrf
                    <select name="action">
                        @if (in_array($r->target_type, ['comment', 'danmu']))<option value="hide">隐藏内容</option>@endif
                        <option value="offline">下架作品</option>
                        <option value="legal">转法务</option>
                        <option value="none">忽略</option>
                    </select>
                    <input type="text" name="note" placeholder="处理备注（必填，≥5字）" required class="handle-note">
                    <button class="btn small" type="submit"
                            onclick="return this.form.querySelector('.handle-note').value.length >= 5 || (alert('处理动作必须附强制备注（≥5字）'), false)">
                        提交
                    </button>
                </form>
            </td>
        </tr>
    @empty
        <tr><td colspan="5">队列为空，天下太平</td></tr>
    @endforelse
</table>
{{ $reports->links() }}
@endsection
