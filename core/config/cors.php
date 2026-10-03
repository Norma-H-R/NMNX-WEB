<?php

/*
|--------------------------------------------------------------------------
| 跨域（CORS）
|--------------------------------------------------------------------------
|
| 前后端完全分离：face(官网) 与 admin(管理端) 是各自独立的前端，跨域调用本服务。
| 所以来源必须显式列白名单，不能用默认的 '*'。
|
| 默认值覆盖本地开发的两个前端（Nuxt 3000 / Vite 3100）。
| 上线时通过环境变量 CORS_ALLOWED_ORIGINS 覆盖，例如：
|   CORS_ALLOWED_ORIGINS=https://nmnx.com,https://admin.nmnx.com
| 逗号分隔，改完记得 php artisan config:clear。
|
| supports_credentials 保持 false：admin 端走的是 Sanctum 的 Bearer 令牌
| （Authorization 头），不是 SPA 的 Cookie 会话，因此不需要携带凭证。
| 一旦哪天真要改成 Cookie 会话，这里要变成 true，且 allowed_origins 不能再用通配。
|
*/

$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:3000,http://localhost:3100,http://127.0.0.1:3000,http://127.0.0.1:3100'
    ))
)));

return [

    // 只有 API 需要跨域。sanctum/csrf-cookie 是 SPA Cookie 模式才用的，
    // 我们走 Bearer 令牌，所以不暴露它。
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // 预检结果缓存 1 小时，省掉每个跨域请求都要先发 OPTIONS 的开销。
    'max_age' => 3600,

    'supports_credentials' => false,

];
