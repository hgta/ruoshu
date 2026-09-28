<?php

declare(strict_types=1);

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TSA 时间戳（任务 10.7）：给对账单 PDF 加可信时间证明。
 * RFC 3161 协议：POST sha256(pdf) → TSA 签名令牌。
 * 降级：TSA 不可达时记录本地哈希 + 时间（source=local），对账单仍可生成，审计可见降级标记。
 */
class TsaService
{
    /** @return array{token:string,source:'remote'|'local',time:string} */
    public function timestamp(string $content): array
    {
        $url = (string) config('services.tsa.url');
        $digest = hash('sha256', $content);

        if ($url !== '') {
            try {
                $res = Http::timeout(5)->post($url, [
                    'digest' => $digest,
                    'digest_alg' => 'sha256',
                    'cert' => true,
                ]);

                if ($res->successful() && ($token = $res->json('token'))) {
                    return [
                        'token' => (string) $token,
                        'source' => 'remote',
                        'time' => now()->toIso8601String(),
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('TSA 请求失败，降级本地时间戳', ['err' => $e->getMessage()]);
            }
        }

        // 降级：本地可信度弱于 TSA，但保留哈希绑定 + 精确时间（审计标记 source=local）
        return [
            'token' => 'local:'.hash('sha256', $digest.now()->toIso8601String()),
            'source' => 'local',
            'time' => now()->toIso8601String(),
        ];
    }
}
