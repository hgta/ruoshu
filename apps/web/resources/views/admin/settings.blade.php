@extends('layouts.app')
@section('title', '运营位 · 管理后台')

@section('content')
<h1>首页运营位（保存即生效，免部署）</h1>
@if (session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif

<form method="POST" action="{{ route('admin.settings') }}">
    @csrf
    <section class="license-card">
        <h3>精选横幅</h3>
        <p><label>主标题</label><input type="text" name="banner_title" value="{{ $home['banner_title'] }}" maxlength="60" required></p>
        <p><label>副标题</label><input type="text" name="banner_sub" value="{{ $home['banner_sub'] }}" maxlength="80" required></p>
    </section>

    <section class="license-card">
        <h3>精选作品（逗号分隔 book_id）</h3>
        <p><input type="text" name="featured_book_ids" value="{{ implode(',', $home['featured_book_ids'] ?? []) }}"
                  placeholder="例如 3,17,42（留空则用系统默认热门）"></p>
        <p class="hint">读者端首页 60 秒缓存窗口内自动刷新，无需发版。</p>
    </section>

    <button class="btn" type="submit">保存并生效</button>
</form>
@endsection
