@extends('layouts.app')
@section('title', '管理后台 · 若书')

@section('content')
<h1>管理后台</h1>
<p class="hint">生产部署：admin 独立子域 + 网关 IP 白名单 + TOTP 2FA（上线前接入，任务 13.7）。</p>

<div class="cards">
    <div class="card"><b>待处理举报</b><span><a href="{{ route('admin.moderation') }}">{{ $pendingReports }}</a></span></div>
    <div class="card"><b>待审核提现</b><span><a href="{{ route('admin.withdrawals') }}">{{ $pendingWithdrawals }}</a></span></div>
    <div class="card"><b>已下架作品</b><span>{{ $offlineBooks }}</span></div>
</div>

<div class="toolbar">
    <a class="btn" href="{{ route('admin.moderation') }}">审核队列</a>
    <a class="btn" href="{{ route('admin.withdrawals') }}">财务审核</a>
    <a class="btn" href="{{ route('admin.ledger.verify') }}">账本校验</a>
    <a class="btn" href="{{ route('admin.settings') }}">运营位</a>
    <a class="btn" href="{{ route('admin.audits') }}">审计日志</a>
</div>

<h2>最近审计留痕</h2>
<table class="table">
    <tr><th>时间</th><th>操作人</th><th>动作</th><th>目标</th><th>签名</th></tr>
    @forelse ($recentAudits as $a)
        <tr>
            <td>{{ $a->created_at->format('m-d H:i') }}</td>
            <td>{{ $a->actor?->name ?? "#{$a->actor_id}" }}</td>
            <td><code>{{ $a->action }}</code></td>
            <td>{{ $a->target_type }}#{{ $a->target_id }}</td>
            <td title="操作人签名">{{ \Str::limit($a->actor_sig, 12) }}</td>
        </tr>
    @empty
        <tr><td colspan="5">暂无留痕</td></tr>
    @endforelse
</table>
@endsection
