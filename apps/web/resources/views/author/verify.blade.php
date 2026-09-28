@extends('layouts.app')
@section('title', '作者实名认证 · 若书')

@section('content')
<div class="verify-box">
    <h2>作者实名认证</h2>
    <p class="hint">实名信息仅存哈希与脱敏名，明文不落库（隐私最小化）</p>

    @if ($profile && $profile->verify_status === \App\Models\AuthorProfile::VERIFY_PASSED)
        <div class="flash ok">已认证：{{ $profile->pen_name }}（{{ $profile->real_name_masked }}）</div>
    @else
        @if ($profile && $profile->verify_status === \App\Models\AuthorProfile::VERIFY_PENDING)
            <div class="flash">实名信息已提交，等待审核</div>
        @endif
        <form method="POST" action="/author/verify">
            @csrf
            <input type="text" name="pen_name" placeholder="笔名（对外展示）" required maxlength="32">
            <input type="text" name="real_name" placeholder="真实姓名" required maxlength="32">
            <input type="text" name="id_number" placeholder="身份证号（18 位）" required maxlength="18">
            <button type="submit">提交认证</button>
        </form>
    @endif
</div>
@endsection