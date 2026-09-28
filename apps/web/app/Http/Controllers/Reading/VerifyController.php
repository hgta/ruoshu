<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reading;

use App\Http\Controllers\Controller;
use App\Models\EvidenceRecord;
use App\Models\Paragraph;
use App\Services\Reading\ParagraphNormalizer;
use Illuminate\Http\Request;

/**
 * 公开验证页（任务 6.5）：粘贴段落 → 现场规范化 + SHA-256 → 匹配段落指纹。
 * 不登录、不查正文原文——只比对哈希（指纹库），原文永不通过此接口泄露。
 */
class VerifyController extends Controller
{
    public function __construct(private readonly ParagraphNormalizer $normalizer) {}

    public function show()
    {
        return view('verify');
    }

    public function check(Request $request)
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'min:10', 'max:10000'],
        ]);

        // 1. 现场哈希（与发布时边车同一套规范化）
        $hashes = $this->normalizer->hashAll($validated['text']);
        $matched = Paragraph::query()
            ->whereIn('hash', array_values($hashes))
            ->with('chapter.book:id,title')
            ->get();

        if ($matched->isEmpty()) {
            return view('verify', [
                'input' => $validated['text'],
                'results' => collect(),
                'notFound' => true,
            ]);
        }

        // 2. 指纹命中 → 拉取对应章节证据（链上状态 + merkle root + 时间）
        $results = $matched->map(function (Paragraph $p) {
            $evidence = EvidenceRecord::query()
                ->where('chapter_id', $p->chapter_id)
                ->where('version', $p->version)
                ->orderByDesc('id')
                ->first();

            return [
                'paragraph' => $p,
                'evidence' => $evidence,
            ];
        });

        return view('verify', [
            'input' => $validated['text'],
            'results' => $results,
            'checkedAt' => now()->format('Y-m-d H:i:s'),
        ]);
    }
}
