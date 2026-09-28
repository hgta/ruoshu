@extends('layouts.app')
@section('title', '作者中心 · 若书')

@section('content')
<div class="author-dashboard">
    <h2>作者中心</h2>

    @if (! auth()->user()->authorProfile || auth()->user()->authorProfile->verify_status !== \App\Models\AuthorProfile::VERIFY_PASSED)
        <div class="flash err">
            尚未完成实名认证 —— 付费功能需要实名。 <a href="/author/verify">去认证 →</a>
        </div>
    @endif

    <div class="dash-links">
        <a href="{{ route('author.books') }}" class="dash-card"><b>作品管理</b><span>创建 / 章节 / 设置</span></a>
        <a href="{{ route('author.books.create') }}" class="dash-card"><b>+ 开新书</b><span>选分类 · 标签 · 授权</span></a>
        {{-- 收益看板（任务 10.5）/ 水印溯源（任务 7.4）/ 数据导出（任务 11.2）待实现 --}}
        <div class="dash-card dim"><b>收益</b><span>即将上线</span></div>
        <div class="dash-card dim"><b>溯源工作台</b><span>即将上线</span></div>
    </div>
</div>
@endsection