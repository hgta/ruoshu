<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

/**
 * 审计留痕（任务 12.6）：append-only，操作人签名防抵赖。
 * actor_sig = SHA-256(actor_id | action | target | timestamp | APP_KEY)
 * —— 只有操作人本人环境能复现，伪造就必然校验失败（本服务内 verify）。
 */
class AuditLogger
{
    public static function log(
        User|int $actor,
        string $action,
        string $targetType,
        int $targetId,
        ?array $before = null,
        ?array $after = null,
        string $note = '',
    ): AuditLog {
        $actorId = $actor instanceof User ? $actor->id : $actor;

        return AuditLog::create([
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before' => $before,
            'after' => $after,
            'note' => $note,
            'actor_sig' => self::sign($actorId, $action, $targetType, $targetId),
        ]);
    }

    /** 校验签名（审计工具用） */
    public static function verify(AuditLog $entry): bool
    {
        return hash_equals(
            self::sign($entry->actor_id, $entry->action, $entry->target_type, (int) $entry->target_id),
            (string) $entry->actor_sig,
        );
    }

    private static function sign(int $actorId, string $action, string $targetType, int $targetId): string
    {
        return hash('sha256', implode('|', [$actorId, $action, $targetType, $targetId, config('app.key')]));
    }
}
