@extends('layouts.app')
@section('title', '水印披露 · '.$book->title.' · 作者工作台')

@section('content')
<div class="author-panel">
    <h2>水印披露与预览 — {{ $book->title }}</h2>

    <div class="disclosure-card">
        <h3>我们如何保护您的付费正文</h3>
        <p>
            每当读者解锁一个付费章节，系统会以<b>不可见的零宽字符水印</b>按读者身份对正文做
            per-user 渲染。同一章节，不同读者看到的文本在视觉上完全一致，但内部携带的身份标记不同。
            一旦正文被复制外流，可通过提取水印定位到泄露源账号。
        </p>
        <ul>
            <li>免费章节<b>不</b>嵌水印</li>
            <li>水印不改变任何可见内容、不收集额外信息</li>
            <li>溯源结果仅作者与平台可见；低置信只出人工比对报告，不自动封号</li>
            <li>当前本书已分发水印正文：<b>{{ number_format($seedCount) }}</b> 份，历史溯源 {{ number_format($traceCount) }} 次</li>
        </ul>
    </div>

    @if ($chapter && $previewContent !== null)
        <div class="preview-card">
            <h3>读者同款预览 — {{ $chapter->title }}（v{{ $chapter->version }}）</h3>
            <p class="hint">
                以下即读者看到的版本（以您自己的账号渲染，含同款水印）。
                复制此文本即可在<a href="{{ url('/author/trace') }}">溯源工作台</a>体验提取流程。
            </p>
            <div class="watermark-preview">
                @foreach (preg_split('/\n\s*\n/u', trim($previewContent)) as $para)
                    <p>{{ $para }}</p>
                @endforeach
            </div>
        </div>
    @else
        <div class="preview-card">
            <h3>读者同款预览</h3>
            <p class="hint">本书暂无已发布的付费章节——付费章节发布后可在此预览带水印的读者版本。</p>
        </div>
    @endif
</div>
@endsection
