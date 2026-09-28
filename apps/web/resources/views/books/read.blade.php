@extends('layouts.app')
@section('title', $chapter->title.' · '.$book->title)

@section('content')
<article class="read-page" data-chapter="{{ $chapter->id }}" data-book="{{ $book->id }}"
         data-user="{{ auth()->id() }}" data-ws="{{ config('services.realtime.ws_url') }}">
    <header class="read-head">
        <a href="{{ route('books.show', $book) }}">← {{ $book->title }}</a>
        <h1>第{{ $chapter->chapter_no }}章 · {{ $chapter->title }}</h1>
    </header>

    {{-- 段落锚点渲染：每段独立 P，可绑定段评/弹幕（任务 9.x 挂载点） --}}
    <div class="read-body" id="read-body">
        @foreach ($content as $i => $para)
            <p class="para" data-para="{{ $i + 1 }}">{{ $para }}</p>
        @endforeach
    </div>

    {{-- 弹幕层 + 发送框（任务 9.3 / 9.5）--}}
    <div class="danmu-layer" id="danmu-layer" aria-hidden="true"></div>
    <div class="danmu-bar">
        <input type="text" id="danmu-input" maxlength="50" placeholder="发条弹幕（登录后可用）"
               @unless(auth()->check()) disabled @endunless>
        <button id="danmu-send" class="btn" @unless(auth()->check()) disabled @endunless>发送</button>
    </div>
    <div class="danmu-status hint" id="danmu-status"></div>

    @if ($authorNote)
        <aside class="author-note"><b>作者有话说：</b>{{ $authorNote }}</aside>
    @endif

    <footer class="read-actions">
        @if ($prev)<a href="{{ route('chapters.read', $prev) }}">上一章</a>@endif
        <button id="btn-shelf" data-book="{{ $book->id }}">收藏</button>
        {{-- 打赏入口（任务 10.1；feature flag 关闭时隐藏） --}}
        @if (config('features.payment'))<button id="btn-donate">投喂作者</button>@endif
        @if ($next)<a href="{{ route('chapters.read', $next) }}">下一章</a>@endif
    </footer>

    {{-- 打赏面板（任务 10.1：礼物选择 + 自由金额 → 模拟收银台） --}}
    <div class="donate-modal hidden" id="donate-modal">
        <div class="donate-panel">
            <h3>投喂作者</h3>
            <div class="gift-grid" id="gift-grid"><span class="hint">礼物加载中…</span></div>
            <div class="donate-custom">
                <input type="number" id="donate-amount" placeholder="自定义金额（¥）" min="1" step="0.5">
            </div>
            <div class="donate-channels">
                <button class="btn" data-channel="wechat">微信支付</button>
                <button class="btn" data-channel="alipay">支付宝</button>
            </div>
            <button class="btn ghost" id="donate-close">取消</button>
            <p class="hint" id="donate-status"></p>
        </div>
    </div>
</article>

<script src="/js/read.js" defer></script>
@endsection