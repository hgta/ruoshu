<?php

declare(strict_types=1);

namespace App\Services\Evidence;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 腾讯至信链驱动（主力，1 元/条按量计费）。
 * 接入前需在腾讯云控制台开通至信链版权存证 API 并配置密钥。
 * MVP 未配置时 submit() 抛异常 → 队列重试 → 死信，本地证据不丢（本地先行留底）。
 */
class ZhixinChainDriver implements ChainDriver
{
    public function name(): string
    {
        return 'zhixin';
    }

    public function submit(array $payload): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('至信链 API 未配置');
        }

        // 腾讯云 API 3.0 签名（TC3-HMAC-SHA256）
        $res = Http::withHeaders($this->signHeaders($payload))
            ->post(config('services.zhixin.api_url'), [
                'ProductId' => config('services.zhixin.product_id'),
                'EvidenceHash' => $payload['merkle_root'],
                'EvidenceInfo' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);

        if ($res->failed()) {
            throw new RuntimeException("至信链提交失败: {$res->status()}");
        }

        $txId = $res->json('Response.EvidenceId')
            ?? $res->json('Response.TxFinalizeInfo.TxHash');

        if (! $txId) {
            throw new RuntimeException('至信链返回缺 tx_id：'.$res->body());
        }

        return (string) $txId;
    }

    public function verify(string $txId): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('至信链 API 未配置');
        }

        $res = Http::withHeaders($this->signHeaders(['tx_id' => $txId]))
            ->post(config('services.zhixin.api_url'), [
                'Action' => 'QueryEvidence',
                'EvidenceId' => $txId,
            ]);

        return $res->json('Response') ?? [];
    }

    private function isConfigured(): bool
    {
        return (bool) (config('services.zhixin.api_url') && config('services.zhixin.key'));
    }

    /** TODO: 换成腾讯云 TC3 签名 SDK 后此方法即完整；MVP 用占位头 */
    private function signHeaders(array $payload): array
    {
        return [
            'Authorization' => 'TC3 '.config('services.zhixin.key'),
            'Content-Type' => 'application/json',
        ];
    }
}
