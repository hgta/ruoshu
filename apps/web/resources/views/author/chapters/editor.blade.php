@extends('layouts.app')
@section('title', '写章节 · '.$book->title)

@section('content')
<div class="editor" data-book="{{ $book->id }}"
     data-chapter="{{ $chapter?->id ?? '' }}"
     data-draft-status="none">
    <h2>{{ $chapter ? '编辑第'.$chapter->chapter_no.'章' : '写新章' }} · 《{{ $book->title }}》</h2>

    <input id="ch-title" type="text" placeholder="章节标题" maxlength="128"
           value="{{ $chapter?->title ?? '' }}">

    <textarea id="ch-content" rows="20" placeholder="正文（空行分段，单章 ≤10 万字）
自动保存：每 10 秒草稿自动保存">{{ $content }}</textarea>
    <p id="save-status" class="hint"></p>

    <div class="publish-row">
        <label><input type="checkbox" id="ch-paid"> VIP 章节（付费）</label>
        <label>定价 <select id="ch-price">
            <option value="10">¥0.1</option>
            <option value="20" selected>¥0.2（默认）</option>
            <option value="30">¥0.3</option>
            <option value="50">¥0.5</option>
        </select></label>
        <input type="text" id="ch-note" placeholder="作者有话说（可选）" maxlength="2000">
        <button id="btn-publish" class="btn primary">发布</button>
        @if ($chapter && $chapter->status === \App\Models\Chapter::STATUS_PUBLISHED)
            <button id="btn-revise" class="btn">发修订版</button>
        @endif
    </div>
    <p class="hint">发布即触发：段落指纹 + 区块链存证（异步，不阻塞）+ VIP 水印规划</p>
</div>

<script src="/js/editor.js" defer></script>
@endsection