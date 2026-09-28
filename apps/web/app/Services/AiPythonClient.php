<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Python 边车客户端：指纹 / 水印 / 溯源。
 * 仅内网调用，3 秒超时，失败不阻塞主流程。
 */
class AiPythonClient
{
    private PendingRequest $http;

    public function __construct()
    {
        $this->http = Http::baseUrl((string) config('services.ai_python.base_url', 'http://127.0.0.1:9000'))
            ->timeout(3)
            ->retry(2, 200);
    }

    /** 章节指纹：分段 SHA-256 + Merkle root */
    public function fingerprintChapter(string $text, string $chapterId): array
    {
        return $this->post('/v1/fingerprint/chapter', [
            'text' => $text,
            'chapter_id' => $chapterId,
        ]);
    }

    /** 水印规划（发布时预计算锚点，读时纯替换） */
    public function planWatermark(string $text, int $userId, int $chapterId, int $version = 1): array
    {
        return $this->post('/v1/watermark/plan', [
            'text' => $text,
            'user_id' => $userId,
            'chapter_id' => $chapterId,
            'version' => $version,
        ]);
    }

    /** 读时嵌入（纯字符串替换，per (user,chapter) 确定性） */
    public function embedWatermark(string $text, int $payload): string
    {
        $res = $this->post('/v1/watermark/embed', [
            'text' => $text,
            'payload' => $payload,
        ]);

        return $res['watermarked_text'];
    }

    /** 溯源：提取水印 payload */
    public function extractWatermark(string $text, ?int $expectedChapterId = null): array
    {
        return $this->post('/v1/watermark/extract', [
            'text' => $text,
            'expected_chapter_id' => $expectedChapterId,
        ]);
    }

    /** 取证包 PDF 生成（base64 返回，队列异步调用） */
    public function forensicPdf(array $report): string
    {
        $res = $this->post('/v1/forensic/pdf', $report);

        return base64_decode((string) $res['pdf_base64']);
    }

    /** 月度对账单 PDF 生成（任务 10.7，队列异步调用） */
    public function statementPdf(array $statement): string
    {
        $res = $this->post('/v1/statement/pdf', $statement);

        return base64_decode((string) $res['pdf_base64']);
    }

    private function post(string $path, array $data): array
    {
        $res = $this->http->post($path, $data);

        if ($res->failed()) {
            throw new RuntimeException("AI 边车调用失败 [{$path}] {$res->status()}");
        }

        return $res->json();
    }
}
