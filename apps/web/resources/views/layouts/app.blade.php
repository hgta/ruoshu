<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', '若书 · 原创小说平台')</title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
<nav class="topnav">
    <a href="{{ url('/') }}" class="logo">若书</a>
    <a href="{{ route('explore') }}">分类</a>
    <a href="{{ route('verify') }}">存证验证</a>
    @auth
        <a href="{{ route('me.bookshelf') }}">书架</a>
        @role('author')<a href="{{ route('author.dashboard') }}">作者中心</a>@endrole
        <form method="POST" action="{{ route('logout') }}" class="inline">@csrf<button>退出</button></form>
    @else
        <a href="{{ route('login') }}" class="btn">登录 / 注册</a>
    @endauth
</nav>

<main>
    @if (session('status'))<div class="flash ok">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="flash err">{{ session('error') }}</div>@endif
    @yield('content')
</main>

<footer class="site-footer">
    <p>若书 · 作品归作者所有 · {{ date('Y') }}</p>
</footer>
</body>
</html>