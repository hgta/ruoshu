<?php

declare(strict_types=1);

namespace App\Http\Controllers\Author;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\Paragraph;
use App\Models\User;
use App\Models\WatermarkAudit;
use App\Models\WatermarkSeed;
use App\Services\AiPythonClient;
use App\Services\Reading\ParagraphNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 溯源工作台（任务 7.4）：粘贴盗版文本 →
 *   ① 指纹比对（段落 SHA-256 命中 → 定位作品/章节）
 *   ② 零宽水印提取（user_id + ECC 校验）
 *   → 嫌疑账号 + 置信度
 * 红线：低置信只出人工比对报告，绝不自动封禁。
 */
class TraceController extends Controller
{
    public function __construct(
        private readonly AiPythonClient $ai,
        private readonly ParagraphNormalizer $normalizer,
    ) {}

    public function show()
    {
        return view('author.trace');
    }

    public function check(Request $request)
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'min:10', 'max:100000'],
        ]);
        $text = $validated['text'];

        // ① 指纹比对：现场哈希 → 命中段落 → 定位章节（多个章节按命中数排序）
        $hashes = $this->normalizer->hashAll($text);
        $paraHits = Paragraph::query()
            ->whereIn('hash', array_values($hashes))
            ->with('chapter.book:id,title,user_id')
            ->get();

        $chapterIds = $paraHits->pluck('chapter_id')->unique()->values();

        // ② 水印提取（任选命中章节作为 expected，提高 ECC 判定的置信度）
        $extraction = null;
        try {
            $extraction = $this->ai->extractWatermark($text, $chapterIds->first());
        } catch (\Throwable $e) {
            Log::warning('水印提取失败（边车不可用），仅输出指纹比对结果', ['err' => $e->getMessage()]);
        }

        // ③ 置信度裁决 + 嫌疑账号
        $suspect = null;
        $confidence = $extraction['confidence'] ?? 'low';
        $seedMatched = false;

        $extractedUserId = $extraction['user_id'] ?? null;
        if ($extractedUserId !== null && ($extraction['ecc_valid'] ?? false)) {
            // 水印 seed 佐证：该 (user, chapter) 确实被分发过带水印正文
            $seedMatched = WatermarkSeed::where('user_id', $extractedUserId)
                ->whereIn('chapter_id', $chapterIds)
                ->exists();

            $suspect = User::find($extractedUserId);
        }

        // seed 命中 + ECC 通过 = high；仅 ECC 通过 = medium；其余 = low
        if ($confidence === 'high' && ! $seedMatched) {
            $confidence = 'medium';
        }
        if ($confidence === 'medium' && $seedMatched) {
            $confidence = 'high';
        }

        // ④ 审计留痕（溯源是敏感动作，全量入 watermark_audits）
        $bookId = $paraHits->first()?->book_id
            ?? ($extraction['chapter_id'] ?? null
                ? Chapter::find($extraction['chapter_id'])?->book_id
                : null);

        if ($bookId !== null) {
            WatermarkAudit::create([
                'book_id' => $bookId,
                'user_id' => $suspect?->id,
                'pirate_text_excerpt' => mb_substr($text, 0, 2000),
                'confidence' => match ($confidence) {
                    'high' => 1.0,
                    'medium' => 0.6,
                    default => 0.2,
                },
                'match_kind' => $suspect ? 1 : ($paraHits->isNotEmpty() ? 2 : 3),
                'status' => 0,
                'evidence_record_id' => null,
            ]);
        }

        return view('author.trace', [
            'input' => $text,
            'paraHits' => $paraHits,
            'chapterIds' => $chapterIds,
            'extraction' => $extraction,
            'suspect' => $suspect,
            'confidence' => $confidence,
            'seedMatched' => $seedMatched,
            'autoBan' => false, // 红线：永不自动封禁
        ]);
    }
}
