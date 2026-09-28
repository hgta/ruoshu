<?php

declare(strict_types=1);

namespace App\Services\Reading;

/**
 * 段落规范化（公开验证页用）：与 Python 边车 normalize.py 完全对齐。
 * 规则：CRLF→LF → 省略号归一 → 全角/特殊空白折叠 → 空行分段 → 段内换行折叠。
 * 验证页现场计算 SHA-256 时必须与发布时边车产出一致，否则无法匹配指纹。
 */
class ParagraphNormalizer
{
    /** 规范化并切分段落（与边车 normalize(split_paragraphs=true) 一致） */
    public function paragraphs(string $text): array
    {
        // 1. 统一换行
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // 2. 标点归一（最小可逆集，与 _PUNCT_NORMALIZE 对齐）
        $text = str_replace('…', '……', $text);

        // 3. 行内空白折叠（\u00A0 \u2000-\u200A \u205F \u3000 → 单半角空格）
        $text = preg_replace('/[\x{00A0}\x{2000}-\x{200A}\x{205F}\x{3000}]+/u', ' ', $text) ?? $text;

        // 4. 段落切分（按空行）
        $out = [];
        foreach (explode("\n\n", $text) as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            // 段内换行折叠为单空格
            $block = preg_replace('/\s*\n\s*/u', ' ', $block) ?? $block;
            $block = preg_replace('/[\x{00A0}\x{2000}-\x{200A}\x{205F}\x{3000}]+/u', ' ', $block) ?? $block;
            $out[] = $block;
        }

        return $out;
    }

    /** 段落 SHA-256（与边车 _sha256(p.encode("utf-8")) 一致） */
    public function hash(string $paragraph): string
    {
        return hash('sha256', $paragraph);
    }

    /** 现场哈希一批段落，返回 [para_no => hash] */
    public function hashAll(string $text): array
    {
        $hashes = [];
        foreach ($this->paragraphs($text) as $i => $p) {
            $hashes[$i + 1] = $this->hash($p);
        }

        return $hashes;
    }
}
