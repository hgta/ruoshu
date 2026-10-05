<?php

declare(strict_types=1);

return [
    // 微信开放平台（扫码登录 + 商户支付）
    'wechat' => [
        'app_id' => env('WECHAT_APP_ID'),
        'app_secret' => env('WECHAT_APP_SECRET'),
        'mch_id' => env('WECHAT_MCH_ID'),
    ],

    // 腾讯至信链（版权存证，1 元/条按量）
    'zhixin' => [
        'api_url' => env('ZHIXIN_API_URL'),
        'key' => env('ZHIXIN_API_KEY'),
        'product_id' => env('ZHIXIN_PRODUCT_ID', 1505),
    ],

    // Python AI 边车（仅内网）
    'ai_python' => [
        'base_url' => env('AI_PYTHON_BASE_URL', 'http://127.0.0.1:9000'),
    ],

    // 取证包存储磁盘（开发 local，生产切 OSS）
    'forensic' => [
        'disk' => env('FORENSIC_DISK', 'local'),
    ],

    // Go 实时服务（JWT 颁发给 WebSocket 握手）
    // 部署要点：REALTIME_JWT_ISSUER 必须与 Go 侧 JWT_ISSUER 完全一致，否则握手验签失败。
    // 密钥对：openssl genrsa 生成，私钥给 Laravel（REALTIME_JWT_PRIVATE_KEY），公钥给 Go（JWT_PUBLIC_KEY）。
    'realtime' => [
        'ws_url' => env('REALTIME_WS_URL', 'ws://localhost:8080/ws'),
        'jwt_issuer' => env('REALTIME_JWT_ISSUER', 'ruoshu-realtime'),
        'jwt_private_key' => env('REALTIME_JWT_PRIVATE_KEY', ''),
    ],

    // Meilisearch 搜索（未配置则 SearchService 自动降级 LIKE）
    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST'),
        'key' => env('MEILISEARCH_KEY'),
    ],
];
