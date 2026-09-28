@extends('layouts.app')
@section('title', '登录 · 若书')

@section('content')
<div class="auth-box">
    <h2>登录若书</h2>

    @if ($wechatReady)
        <a href="{{ $wechatUrl }}" class="btn wechat">微信扫码登录</a>
        <p class="hint">首次扫码将自动注册（自动昵称），登录后回到原阅读位置</p>
    @else
        <div class="flash err">微信登录暂未开放，请使用邮箱注册</div>
    @endif

    <hr>

    <form method="POST" action="{{ route('auth.password') }}">
        @csrf
        <input type="email" name="email" placeholder="邮箱" required>
        <input type="password" name="password" placeholder="密码" required>
        <label><input type="checkbox" name="remember" value="1"> 记住我</label>
        <button type="submit">登录</button>
    </form>

    @if (config('features.registration'))
        <p class="hint">没有账号？<a href="#" onclick="document.getElementById('reg').style.display='block'">注册</a></p>
        <form id="reg" method="POST" action="{{ route('register') }}" style="display:none">
            @csrf
            <input type="text" name="name" placeholder="昵称（登录后可改）" required maxlength="16">
            <input type="email" name="email" placeholder="邮箱" required>
            <input type="password" name="password" placeholder="密码（≥8 位）" required>
            <button type="submit">注册并登录</button>
        </form>
    @endif
</div>
@endsection