@extends('layouts.app')
@section('title', '收银台 · 若书')

@section('content')
<div class="cashier">
    <h1>若书收银台</h1>
    <p class="hint">开发环境模拟收银台：点击「模拟支付成功」触发渠道回调（生产为微信/支付宝 H5 拉起，回调需验签 + 金额核对）。</p>
    <p class="mono">{{ $tradeNo }}</p>
    <button class="btn" id="mock-pay">模拟支付成功</button>
    <p class="hint" id="pay-result"></p>
</div>

<script>
(() => {
    const btn = document.getElementById('mock-pay');
    const result = document.getElementById('pay-result');
    const tradeNo = @json($tradeNo);

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        result.textContent = '支付处理中…';
        try {
            // 模拟渠道回调 → 平台结算（幂等）
            const res = await fetch(`/pay/${encodeURIComponent(tradeNo)}/callback`, {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
            });
            const data = await res.json();
            if (data.code === 0) {
                result.textContent = data.settled ? '支付成功，已到账！' : '该交易已结算过（幂等）';
                setTimeout(() => window.close(), 800);
            } else {
                result.textContent = '支付失败，请重试';
                btn.disabled = false;
            }
        } catch (e) {
            result.textContent = '网络异常';
            btn.disabled = false;
        }
    });
})();
</script>
@endsection
