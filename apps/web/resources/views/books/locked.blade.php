@extends('layouts.app')
@section('title', '本章需要解锁 · 若书')

@section('content')
<div class="locked-box">
    <h2>「{{ $chapter->title }}」为 VIP 章节</h2>

    @if ($reason === 'login')
        <p>登录后即可继续阅读（未登录可免费试读每本书前 3 章）</p>
        <a href="{{ route('login') }}" class="btn">立即登录</a>
    @else
        <p>本章定价 ¥{{ number_format($chapter->price / 100, 1) }}，单章解锁或开通月卡畅读</p>
        @if (config('features.payment'))
            <a href="#" class="btn">单章解锁</a>
            <a href="#" class="btn">开通月卡</a>
        @else
            <p class="hint">支付功能即将开放，敬请期待</p>
        @endif
    @endif
</div>
@endsection