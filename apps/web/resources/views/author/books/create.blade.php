@extends('layouts.app')
@section('title', '开新书 · 若书')

@section('content')
<div class="book-create">
    <h2>创建新作品</h2>
    <form method="POST" action="{{ route('author.books') }}" enctype="multipart/form-data">
        @csrf
        <label>书名 <input type="text" name="title" required maxlength="64"></label>
        <label>简介 <textarea name="intro" required maxlength="500" rows="4"
            placeholder="一句话吸引读者（将进入 RSS 摘要）"></textarea></label>

        <label>分类
            <select name="category_id" required>
                @foreach ($categories as $id => $cat)
                    <option value="{{ $id }}">{{ $cat['name'] }}（{{ implode('/', $cat['sub']) }}）</option>
                @endforeach
            </select>
        </label>

        <label>标签（3-8 个，空格分隔）<input type="text" name="tags" required
            placeholder="甜文 重生 女主强"></label>

        <label>版权授权
            <select name="license" required>
                @foreach ($licenseOptions as $id => $label)
                    <option value="{{ $id }}" @selected($id === \App\Models\Book::LICENSE_CC_BY_NC_ND)>
                        {{ $label }}@if($id === \App\Models\Book::LICENSE_CC_BY_NC_ND)（推荐）@endif
                    </option>
                @endforeach
            </select>
        </label>
        <p class="hint">作者保留一切版权；CC 协议只决定读者可以怎样转载你的免费章节。随时可改，变更历史会留痕。</p>

        <label>封面（可选）<input type="file" name="cover" accept="image/*"></label>

        <button type="submit">创建作品</button>
    </form>
</div>
@endsection