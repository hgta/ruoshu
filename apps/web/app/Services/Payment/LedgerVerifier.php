<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\LedgerEntry;

/**
 * 账本校验工具（任务 10.7）：检测链式哈希断裂。
 * 规则：
 *   1. 每条 entry_hash == computeHash(字段..., prev_hash)
 *   2. entry[i].prev_hash == entry[i-1].entry_hash（首条 prev_hash 为 null）
 * 断裂即触发告警：唯一真源红线被破坏，需按备份恢复并审计。
 */
class LedgerVerifier
{
    /** @return array{ok:bool,total:int,broken_at:?int,reasons:array<int,string>} */
    public function verify(?int $limit = null): array
    {
        $query = LedgerEntry::orderBy('id');
        if ($limit !== null) {
            $query->limit($limit);
        }
        $entries = $query->get(['id', 'user_id', 'book_id', 'source_type', 'source_id', 'gross', 'fee', 'net', 'entry_hash', 'prev_hash']);

        $reasons = [];
        $expectedPrev = null; // 首条必须 prev_hash = null

        /** @var LedgerEntry $e */
        foreach ($entries as $e) {
            // 规则 2：链式前驱连续性（首条期望 null）
            if ($e->prev_hash !== $expectedPrev) {
                $reasons[$e->id] = '前驱哈希断裂';
            }

            // 规则 1：本条哈希重算
            $expect = LedgerEntry::computeHash(
                $e->user_id, $e->book_id, $e->source_type, $e->source_id,
                $e->gross, $e->fee, $e->net, $e->prev_hash,
            );
            if (! hash_equals($expect, $e->entry_hash)) {
                $reasons[$e->id] = ($reasons[$e->id] ?? '').'条目哈希与字段不匹配';
            }

            $expectedPrev = $e->entry_hash;
        }

        return [
            'ok' => $reasons === [],
            'total' => $entries->count(),
            'broken_at' => $reasons === [] ? null : (int) min(array_keys($reasons)),
            'reasons' => $reasons,
        ];
    }
}
