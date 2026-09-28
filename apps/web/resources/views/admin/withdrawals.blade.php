@extends('layouts.app')
@section('title', '财务审核 · 管理后台')

@section('content')
<h1>提现审核</h1>

<div class="toolbar">
    <form method="POST" action="{{ url('admin/statements/generate') }}" class="inline"
          onsubmit="return confirm('为所有作者排期生成上月对账单？')">@csrf<button class="btn">生成上月对账单</button></form>
    <a class="btn" href="{{ route('admin.ledger.verify') }}">账本校验</a>
</div>
@if (session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif

<table class="table">
    <tr><th>申请时间</th><th>作者</th><th>金额</th><th>状态</th><th>操作</th></tr>
    @forelse ($withdrawals as $w)
        <tr>
            <td>{{ $w->created_at->format('Y-m-d H:i') }}</td>
            <td>{{ $w->user?->name }}（#{{ $w->user_id }}）</td>
            <td><b>¥{{ number_format($w->amount / 100, 2) }}</b></td>
            <td>{{ [0 => '审核中', 1 => '已通过待打款', 2 => '已打款', 3 => '已驳回'][$w->status] }}</td>
            <td>
                @if ($w->status === 0)
                    <form method="POST" action="{{ url("admin/withdrawals/{$w->id}/approve") }}" class="inline"
                          onsubmit="return confirm('通过该提现申请？')">@csrf<button class="btn small">通过</button></form>
                    <form method="POST" action="{{ url("admin/withdrawals/{$w->id}/reject") }}" class="inline reject-form">
                        @csrf<input type="hidden" name="note" class="reject-note" value="">
                        <button class="btn small danger" onclick="this.closest('form').querySelector('.reject-note').value = prompt('驳回理由（必填）') ?? ''; return this.closest('form').querySelector('.reject-note').value !== '';">驳回</button>
                    </form>
                @elseif ($w->status === 1)
                    <form method="POST" action="{{ url("admin/withdrawals/{$w->id}/paid") }}" class="inline"
                          onsubmit="return confirm('确认线下打款已完成？')">@csrf<button class="btn small">标记已打款</button></form>
                @else
                    {{ $w->review_note ?? '—' }}
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="5">暂无提现申请</td></tr>
    @endforelse
</table>
{{ $withdrawals->links() }}
@endsection
