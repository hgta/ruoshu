@extends('layouts.app')
@section('title', '审计日志 · 管理后台')

@section('content')
<h1>审计日志（append-only，操作人签名）</h1>
<p class="hint">actor_sig = SHA-256(操作人|动作|目标|APP_KEY)：冒名伪造必然签名校验失败（12.6 防抵赖）。</p>

<table class="table">
    <tr><th>时间</th><th>操作人</th><th>动作</th><th>目标</th><th>变更</th><th>备注</th><th>签名</th><th>校验</th></tr>
    @foreach ($logs as $a)
        <tr>
            <td>{{ $a->created_at->format('Y-m-d H:i') }}</td>
            <td>{{ $a->actor?->name ?? "#{$a->actor_id}" }}</td>
            <td><code>{{ $a->action }}</code></td>
            <td>{{ $a->target_type }}#{{ $a->target_id }}</td>
            <td class="mono small">
                @if ($a->before && isset($a->before['status'])){{ $a->before['status'] }} → {{ $a->after['status'] ?? '?' }}@else — @endif
            </td>
            <td>{{ \Str::limit($a->note, 40) }}</td>
            <td class="mono">{{ \Str::limit($a->actor_sig, 10) }}</td>
            <td>{{ \App\Services\AuditLogger::verify($a) ? '✓' : '✗' }}</td>
        </tr>
    @endforeach
</table>
{{ $logs->links() }}
@endsection
