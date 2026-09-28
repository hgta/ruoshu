@extends('layouts.app')
@section('title', '收益看板 · 若书作者中心')

@section('content')
<h1>收益看板</h1>

<div class="finance-summary cards">
    <div class="card"><b>累计毛额</b><span>¥{{ number_format($summary->gross / 100, 2) }}</span></div>
    <div class="card"><b>平台抽成</b><span>¥{{ number_format($summary->fee / 100, 2) }}</span></div>
    <div class="card"><b>累计净得</b><span>¥{{ number_format($summary->net / 100, 2) }}</span></div>
    <div class="card"><b>可提现余额</b><span>¥{{ number_format($withdrawable / 100, 2) }}</span></div>
</div>

<h2>逐笔明细（毛 / 抽 / 净）</h2>
<table class="table">
    <tr><th>时间</th><th>作品</th><th>来源</th><th>毛额</th><th>抽成</th><th>净得</th><th>费率</th></tr>
    @forelse ($entries as $e)
        <tr>
            <td>{{ $e->created_at->format('m-d H:i') }}</td>
            <td>{{ $e->book?->title ?? '—' }}</td>
            <td>{{ ['donation' => '打赏', 'purchase' => '单购', 'subscription' => '月卡'][$e->source_type] ?? $e->source_type }}</td>
            <td>¥{{ number_format($e->gross / 100, 2) }}</td>
            <td>¥{{ number_format($e->fee / 100, 2) }}</td>
            <td><b>¥{{ number_format($e->net / 100, 2) }}</b></td>
            <td>{{ $e->fee_rate * 100 }}%</td>
        </tr>
    @empty
        <tr><td colspan="7">暂无收益记录</td></tr>
    @endforelse
</table>
{{ $entries->links() }}

<h2>月度对账单</h2>
@if (count($statements) === 0)<p class="hint">每月初自动生成上月对账单（PDF + TSA 时间戳）。</p>@endif
<table class="table">
    <tr><th>周期</th><th>笔数</th><th>毛额</th><th>净得</th><th>TSA</th><th>下载</th></tr>
    @foreach ($statements as $s)
        <tr>
            <td>{{ $s->period }}</td>
            <td>{{ $s->entry_count }}</td>
            <td>¥{{ number_format($s->gross / 100, 2) }}</td>
            <td>¥{{ number_format($s->net / 100, 2) }}</td>
            <td>{{ $s->tsa_source === 'remote' ? '已盖章' : '本地' }}</td>
            <td>@if ($s->status === 1)<a class="btn small" href="{{ route('author.finance.statements.download', $s) }}">下载 PDF</a>@else 生成中… @endif</td>
        </tr>
    @endforeach
</table>
@endsection
