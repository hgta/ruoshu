<?php

declare(strict_types=1);

namespace App\Services\Evidence;

/**
 * 存证链驱动抽象：换链 = 换驱动（三层对冲第 1 道）。
 * 约束：payload 不含原文与明文标题。
 */
interface ChainDriver
{
    /** 驱动名（写入 evidence_records.chain_name） */
    public function name(): string;

    /**
     * 提存证，返回链上交易号。
     *
     * @param  array{merkle_root:string,title_hash:?string,book_id:int,chapter_id:?int,version:int,word_count:?int,prev_chapter_root:?string,published_at:?string}  $payload
     */
    public function submit(array $payload): string;

    /** 在线核验 tx（admin DMCA 流程用） */
    public function verify(string $txId): array;
}
