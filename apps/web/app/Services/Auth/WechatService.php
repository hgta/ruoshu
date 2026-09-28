<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * 微信扫码登录驱动。
 *
 * MVP 说明：开放平台 AppID/Secret 未配置时，QR 流程返回未开通提示；
 * 配置后按微信开放平台标准三步走（authorize → access_token → userinfo）。
 */
class WechatService
{
    private const AUTH_URL = 'https://open.weixin.qq.com/connect/qrconnect';

    private const TOKEN_URL = 'https://api.weixin.qq.com/sns/oauth2/access_token';

    private const USER_URL = 'https://api.weixin.qq.com/sns/userinfo';

    public function isConfigured(): bool
    {
        return (bool) (config('services.wechat.app_id') && config('services.wechat.app_secret'));
    }

    /** 生成扫码跳转地址（state 防 CSRF） */
    public function qrConnectUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'appid' => config('services.wechat.app_id'),
            'redirect_uri' => route('auth.wechat.callback'),
            'response_type' => 'code',
            'scope' => 'snsapi_login',
            'state' => $state,
        ]).'#wechat_redirect';
    }

    /**
     * code 换 openid + 用户信息，登录或注册（自动昵称/头像）。
     */
    public function loginByCode(string $code): User
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('微信登录暂未配置');
        }

        $token = Http::get(self::TOKEN_URL, [
            'appid' => config('services.wechat.app_id'),
            'secret' => config('services.wechat.app_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
        ])->throw()->json();

        $openid = $token['openid'] ?? null;
        $unionid = $token['unionid'] ?? null;
        if (! $openid) {
            throw new RuntimeException('微信授权失败');
        }

        $info = Http::get(self::USER_URL, [
            'access_token' => $token['access_token'],
            'openid' => $openid,
        ])->json();

        return DB::transaction(function () use ($openid, $unionid, $info) {
            $user = User::where('wechat_openid', $openid)->first();

            if (! $user) {
                $user = User::create([
                    'name' => $info['nickname'] ?? User::generateNickname(),
                    'avatar' => $info['headimgurl'] ?? null,
                    'wechat_openid' => $openid,
                    'wechat_unionid' => $unionid,
                ]);
                $user->readerProfile()->create();
            }

            return $user;
        });
    }
}
