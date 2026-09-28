@extends('layouts.app')
@section('title', '提现 · 若书作者中心')

@section('content')
<h1>提现</h1>

<div class="finance-summary cards">
    <div class="card"><b>可提现余额</b><span>¥{{ number_format($withdrawable / 100, 2) }}</span></div>
</div>

@if (! config('features.withdrawal'))
    <div class="flash err">提现功能暂未开放（财务审核流程就绪后开放），收益已安全记账。</div>
@else
    <form method="POST" action="{{ route('author.withdrawals') }}" class="danmu-bar" onsubmit="return confirm('确认申请提现？')">
        @csrf
        <input type="number" name="amount" min="10000" step="100" placeholder="提现金额（分），单笔 ≥¥100" required>
        <button class="btn" type="submit">申请提现</button>
    </form>
@endif

<h2>申请记录</h2>
<table class="table">
    <tr><th>时间</th><th>金额</th><th>状态</th><th>备注</th></tr>
    @forelse ($withdrawals as $w)
        <tr>
            <td>{{ $w->created_at->format('Y-m-d H:i') }}</td>
            <td>¥{{ number_format($w->amount / 100, 2) }}</td>
            <td>{{ [0 => '审核中', 1 => '已通过待打款', 2 => '已打款', 3 => '已驳回'][$w->status] }}</td>
            <td>{{ $w->review_note ?? '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="4">暂无提现记录</td></tr>
    @endforelse
</table>
{{ $withdrawals->links() }}
@endsection
