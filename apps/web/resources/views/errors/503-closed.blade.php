@extends('layouts.app')
@section('title', '暂未开放 · 若书')

@section('content')
<div class="closed-box">
    <h2>🚧 暂未开放</h2>
    <p>{{ $message ?? '该功能即将上线，敬请期待' }}</p>
    <a href="{{ url('/') }}" class="btn">回首页</a>
</div>
@endsection