@extends('layouts.app')
@section('title', 'DMCA 证据面板 · 管理后台')

@section('content')
<h1>DMCA / 侵权处理（任务 12.3）</h1>
<p class="hint">原则：先证据后处置。加载存证记录与取证包 → 下架或转法务；低置信绝不自动删内容。处理动作必须附强制备注。</p>

<section class="license-card">
    <h3>举报信息</h3>
    <p>类型：{{ \App\Models\ModerationReport::KIND_MAP[$report->reason_kind] }} ·
        举报人：{{ $report->reporter?->name }}（#{{ $report->reporter_id }}）</p>
    <p>说明：{{ $report->reason_text ?? '（无补充说明）' }}</p>
    @if ($book)
        <p>目标作品：<a href="{{ url("books/{$book->id}") }}" target="_blank">《{{ $book->title }}》</a>（ID {{ $book->id }}）</p>
    @endif
</section>

@if ($book)
    <section class="license-card">
        <h3>至信链存证记录（版权归属初步证明）</h3>
        @forelse ($evidence as $e)
            <p class="mono">{{ \Str::limit($e->merkle_root, 40) }} · {{ $e->chain_name }}
                · tx {{ \Str::limit($e->tx_id, 24) }} · 证书 {{ $e->cert_no ?? '—' }}
                · {{ [0 => '本地', 1 => '提交中', 2 => '已上链', 3 => '重试中', 4 => '死信'][$e->status] ?? $e->status }}</p>
        @empty
            <p class="hint">无存证记录（原告主张与被告存证将交叉比对）</p>
        @endforelse
    </section>

    <section class="license-card">
        <h3>取证包（溯源证据，转法务时随工单移交）</h3>
        @forelse ($packages as $p)
            <p>取证包 #{{ $p->id }} · {{ $p->created_at->format('Y-m-d H:i') }} ·
                状态 {{ [0 => '排队', 1 => '生成中', 2 => '已完成', 3 => '失败'][$p->status] }}</p>
        @empty
            <p class="hint">作者未生成取证包（非作者发起举报时正常）</p>
        @endforelse
    </section>
@endif

<section class="license-card">
    <h3>处理动作</h3>
    <form method="POST" action="{{ url("admin/moderation/{$report->id}/handle") }}" class="danmu-bar">
        @csrf
        <select name="action">
            <option value="offline">下架作品（存证保留）</option>
            <option value="legal">转法务（下线 + 移交证据）</option>
            <option value="none">忽略（证据不足）</option>
        </select>
        <input type="text" name="note" placeholder="处置理由 / 法务工单号（必填 ≥5 字）" required minlength="5">
        <button class="btn" type="submit">提交处理</button>
    </form>
</section>
@endsection
