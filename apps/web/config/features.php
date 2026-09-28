<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Feature Flags（功能开关）
    |--------------------------------------------------------------------------
    | 控制上线顺序的硬性开关：
    |   - payment: 法务上变后才允许打开（避免二清风险）
    |   - registration: 敏感词库未就绪前必须 false
    |   - ai_watermark: Python 边车健康检查未过前必须 false
    */

    'payment' => (bool) env('FEATURE_PAYMENT_ENABLED', false),
    'registration' => (bool) env('FEATURE_REGISTRATION_ENABLED', true),
    'ai_watermark' => (bool) env('FEATURE_AI_WATERMARK_ENABLED', true),
    // 分账（任务 10.2）：法务材料（作者个体户/分账协议）就绪前必须 false
    'profit_share' => (bool) env('FEATURE_PROFIT_SHARE_ENABLED', false),
    // 提现（任务 10.8）：财务审核 SOP 就绪前 false
    'withdrawal' => (bool) env('FEATURE_WITHDRAWAL_ENABLED', false),
    // 后台 IP 白名单（任务 12.1：逗号分隔；空 = 不启用，生产走 admin 独立子域 + 网关白名单）
    'admin_ip_whitelist' => env('ADMIN_IP_WHITELIST', ''),
];
