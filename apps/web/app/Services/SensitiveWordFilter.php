<?php

declare(strict_types=1);

namespace App\Services;

/**
 * 敏感词过滤（DFA 简化实现）。
 * 上线前置条件（任务 13.1）：敏感词库 + 审核 SOP 就绪后 registration 才开。
 * 词库来源：database/seeders + admin 后台维护（任务 12.x）。
 */
class SensitiveWordFilter
{
    /** @var array<string, array> DFA 树 */
    private array $tree = [];

    public function __construct()
    {
        // MVP：词库先从 config 加载，后续迁 DB + admin 维护
        $words = config('sensitive-words.list', []);
        foreach ($words as $w) {
            $this->insert($w);
        }
    }

    public function contains(string $text): bool
    {
        return $this->firstHit($text) !== null;
    }

    /** 返回命中的第一个词（供审核队列展示） */
    public function firstHit(string $text): ?string
    {
        $len = mb_strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $node = $this->tree;
            $j = $i;
            $lastMatch = null;
            while ($j < $len && isset($node[mb_substr($text, $j, 1)])) {
                $ch = mb_substr($text, $j, 1);
                $node = $node[$ch];
                $j++;
                if (isset($node['__end__'])) {
                    $lastMatch = mb_substr($text, $i, $j - $i);
                }
            }
            if ($lastMatch !== null) {
                return $lastMatch;
            }
        }

        return null;
    }

    private function insert(string $word): void
    {
        $node = &$this->tree;
        $word = mb_trim($word);
        if ($word === '') {
            return;
        }
        foreach (mb_str_split($word) as $ch) {
            $node[$ch] ??= [];
            $node = &$node[$ch];
        }
        $node['__end__'] = true;
    }
}
