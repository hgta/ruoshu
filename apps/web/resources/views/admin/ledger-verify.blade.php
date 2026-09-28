@extends('layouts.app')
@section('title', '账本校验 · 管理后台')

@section('content')
<h1>账本校验（链式哈希完整性）</h1>

@if ($report['ok'])
    <div class="flash ok">账本完整：共 {{ $report['total'] }} 条，链式哈希校验通过。</div>
@else
    <div class="flash err">
        <b>检测到账本断裂！</b> 共 {{ $report['total'] }} 条，首个断裂点 entry #{{ $report['broken_at'] }}。
        唯一真源红线被破坏，请立即按备份恢复并审计访问日志。
    </div>
    <table class="table">
        <tr><th>Entry ID</th><th>原因</th></tr>
        @foreach ($report['reasons'] as $id => $reason)
            <tr><td>#{{ $id }}</td><td>{{ $reason }}</td></tr>
        @endforeach
    </table>
@endif
@endsection
