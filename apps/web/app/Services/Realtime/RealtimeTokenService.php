<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use App\Models\User;
use Firebase\JWT\JWT;

/**
 * WebSocket 握手令牌（任务 9.2）：
 * Laravel 用 RS256 私钥签发 60s 短令牌，Go 网关用公钥验签（双方零共享状态）。
 * 密钥对惰性生成于 storage/app/realtime/，生产用 REALTIME_JWT_PRIVATE_KEY 覆盖。
 */
class RealtimeTokenService
{
    /** 签发握手令牌 */
    public function issue(User $user): string
    {
        $now = time();

        return JWT::encode([
            'uid' => $user->id,
            'role' => $user->isAuthor() ? 'author' : ($user->isAdmin() ? 'admin' : 'reader'),
            'iss' => config('services.realtime.jwt_issuer', 'ruoshu-realtime'),
            'iat' => $now,
            'exp' => $now + 60,
        ], $this->privateKey(), 'RS256');
    }

    /** 导出公钥（给 Go 网关 / 部署脚本取用） */
    public function publicKey(): string
    {
        return $this->keyPair()['public'];
    }

    /** @return array{private:string,public:string} */
    private function keyPair(): array
    {
        $dir = storage_path('app/realtime');
        $privFile = $dir.'/private.pem';
        $pubFile = $dir.'/public.pem';

        if (is_file($privFile) && is_file($pubFile)) {
            return ['private' => file_get_contents($privFile) ?: '', 'public' => file_get_contents($pubFile) ?: ''];
        }

        // 生产：env 注入私钥
        $envPriv = (string) config('services.realtime.jwt_private_key');
        if ($envPriv !== '') {
            return ['private' => $this->normalizePem($envPriv), 'public' => $this->derivePublic($this->normalizePem($envPriv))];
        }

        // 开发/测试：惰性生成（Windows PHP zip 需显式指定 openssl.cnf）
        $config = $this->opensslConfig();
        $opts = $config !== null
            ? ['config' => $config, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]
            : ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $res = openssl_pkey_new($opts);
        openssl_pkey_export($res, $priv, null, $config !== null ? ['config' => $config] : null);
        $pub = openssl_pkey_get_details($res)['key'];

        if (! is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        file_put_contents($privFile, $priv);
        file_put_contents($pubFile, $pub);

        return ['private' => $priv, 'public' => $pub];
    }

    private function privateKey(): string
    {
        return $this->keyPair()['private'];
    }

    private function opensslConfig(): ?string
    {
        $candidates = [
            getenv('OPENSSL_CONF') ?: null,
            dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'extras'.DIRECTORY_SEPARATOR.'ssl'.DIRECTORY_SEPARATOR.'openssl.cnf', // Windows PHP zip 布局
            '/etc/ssl/openssl.cnf',           // Debian/Ubuntu
            '/usr/local/etc/ssl/openssl.cnf', // FreeBSD/Homebrew
        ];
        foreach ($candidates as $cnf) {
            if ($cnf !== null && $cnf !== '' && is_file($cnf)) {
                return $cnf;
            }
        }

        return null;
    }

    /** env 私钥可能是裸 base64 单行，统一为 PEM */
    private function normalizePem(string $key): string
    {
        if (str_contains($key, '-----BEGIN')) {
            return str_replace('\n', "\n", $key);
        }
        $body = chunk_split(base64_decode($key) ?: '', 64, "\n");

        return "-----BEGIN PRIVATE KEY-----\n{$body}-----END PRIVATE KEY-----\n";
    }

    private function derivePublic(string $privPem): string
    {
        $res = openssl_pkey_get_private($privPem);

        return openssl_pkey_get_details($res)['key'];
    }
}
