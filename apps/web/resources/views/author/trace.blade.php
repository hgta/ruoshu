@extends('layouts.app')
@section('title', '溯源工作台 · 作者工作台')

@section('content')
<div class="author-panel">
    <h2>盗版溯源工作台</h2>
    <p class="hint">
        粘贴疑似盗版的正文文本。系统将：① 现场计算段落指纹比对存证库，定位作品与章节；
        ② 提取零宽水印中的身份标记。
        <b>红线：低置信结果只输出人工比对报告，不会自动封禁任何账号。</b>
    </p>

    <form method="POST" action="{{ url('/author/trace') }}">
        @csrf
        <textarea name="text" rows="10" placeholder="粘贴盗版文本（含零宽水印时可直接提取）…" required
                  minlength="10" maxlength="100000">{{ old('text', $input ?? '') }}</textarea>
        @error('text')<p class="error">{{ $message }}</p>@enderror
        <button type="submit" class="btn">开始溯源</button>
    </form>

    @if (isset($confidence))
        @php
            $confLabel = ['high' => '高', 'medium' => '中', 'low' => '低'][$confidence] ?? '低';
            $confClass = ['high' => 'ok', 'medium' => 'warn', 'low' => 'bad'][$confidence] ?? 'bad';
        @endphp

        <div class="trace-result">
            <h3>溯源结果（置信度：<span class="badge {{ $confClass }}">{{ $confLabel }}</span>）</h3>

            <h4>① 指纹比对</h4>
            @if ($paraHits->isNotEmpty())
                <p>命中 {{ $paraHits->count() }} 个段落指纹：</p>
                <ul>
                    @foreach ($paraHits->take(10) as $hit)
                        <li>
                            <a href="{{ url('/books/'.$hit->chapter->book->id) }}">{{ $hit->chapter->book->title }}</a>
                            · {{ $hit->chapter->title }}（第 {{ $hit->para_no }} 段，v{{ $hit->version }}）
                        </li>
                    @endforeach
                </ul>
            @else
                <p>未命中存证指纹库（文本可能经过大幅改写，或非若书作品）。</p>
            @endif

            <h4>② 水印提取</h4>
            @if ($extraction && ($extraction['found_zero_width'] ?? 0) > 0)
                <p>
                    检出零宽字符 {{ $extraction['found_zero_width'] }} 个 ·
                    ECC 校验：{{ ($extraction['ecc_valid'] ?? false) ? '通过' : '失败' }}
                </p>
            @else
                <p>未检出零宽水印（复制/转码过程可能剥离了水印，或来源为免费章节）。</p>
            @endif

            <h4>③ 嫌疑账号</h4>
            @if ($suspect && $confidence === 'high')
                <div class="match-card">
                    <p>
                        <b>{{ $suspect->name }}</b>（ID: {{ $suspect->id }}）
                        @if ($seedMatched)<span class="badge ok">水印种子佐证命中</span>@endif
                    </p>
                    <p class="hint">该账号被分发过所涉章节的带水印正文。您可生成取证包用于维权投诉或转法务。</p>
                </div>
            @elseif ($suspect)
                <div class="match-card">
                    <p>水印指向账号 ID {{ $suspect->id }}，但置信度为{{ $confLabel }}，仅作参考。</p>
                    <p class="hint">低/中置信不构成处置依据：请生成人工比对报告，由人工核验后再决定后续动作。</p>
                </div>
            @else
                <p>无明确嫌疑账号。指纹命中本身已可证明作品的存证归属（含 Merkle root 与链上证书）。</p>
            @endif

            @if ($confidence === 'low')
                <div class="notice">
                    本次溯源置信度低，已按红线仅输出人工比对建议，不涉及任何自动封禁动作。
                </div>
            @endif
        </div>
    @endif
</div>
@endsection
