@extends('layouts.app')
@section('title', '版权存证验证 · 若书')

@section('content')
<div class="verify-page">
    <h2>版权存证验证</h2>
    <p class="hint">
        粘贴任意一段来自若书的作品文字，我们将现场计算 SHA-256 指纹并与存证指纹库比对。
        全程只比对哈希，不存储、不展示您的输入内容。
    </p>

    <form method="POST" action="{{ url('/verify') }}">
        @csrf
        <textarea name="text" rows="8" placeholder="粘贴需要验证的作品段落（至少 10 字）…" required
                  minlength="10" maxlength="10000">{{ old('text', $input ?? '') }}</textarea>
        @error('text')<p class="error">{{ $message }}</p>@enderror
        <button type="submit" class="btn">开始验证</button>
    </form>

    @if (($notFound ?? false))
        <div class="verify-result not-found">
            <h3>未找到匹配的存证记录</h3>
            <p>该段落未命中若书存证指纹库。可能原因：非若书作品 / 修改幅度过大 / 早于存证上线时间。</p>
        </div>
    @endif

    @if (($results ?? collect())->isNotEmpty())
        <div class="verify-result found">
            <h3>✓ 命中存证记录</h3>
            <p class="checked-at">验证时间：{{ $checkedAt }}</p>

            @foreach ($results as $r)
                @php $p = $r['paragraph']; $ev = $r['evidence']; @endphp
                <div class="match-card">
                    <h4>
                        <a href="{{ url('/books/'.$p->chapter->book->id) }}">{{ $p->chapter->book->title }}</a>
                        · {{ $p->chapter->title }}（第 {{ $p->para_no }} 段）
                    </h4>
                    <table class="meta">
                        <tr><td>指纹（SHA-256）</td><td><code>{{ $p->hash }}</code></td></tr>
                        <tr><td>内容版本</td><td>v{{ $p->version }}</td></tr>
                        @if ($ev)
                            <tr><td>Merkle Root</td><td><code>{{ $ev->merkle_root }}</code></td></tr>
                            <tr><td>存证状态</td><td>
                                @if ($ev->status === \App\Models\EvidenceRecord::ST_CONFIRMED)
                                    <span class="badge ok">已上链</span>
                                    {{ $ev->chain_name }} · 证书号 {{ $ev->cert_no }}
                                @elseif ($ev->status === \App\Models\EvidenceRecord::ST_SUBMITTED)
                                    <span class="badge pending">提交中</span>
                                @else
                                    <span class="badge pending">本地存证（待上链）</span>
                                @endif
                            </td></tr>
                            @if ($ev->confirmed_at)
                                <tr><td>链上确认时间</td><td>{{ $ev->confirmed_at->format('Y-m-d H:i:s') }}</td></tr>
                            @endif
                        @else
                            <tr><td>存证状态</td><td><span class="badge pending">指纹已登记</span></td></tr>
                        @endif
                    </table>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
