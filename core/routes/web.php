<?php

use App\Modules\Support\Http\ApiResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| web 路由
|--------------------------------------------------------------------------
|
| 纯 API 后端：这一层不再渲染任何视图，Blade 已从项目里摘除。
| 只留一个 JSON 探针，方便 curl 确认进程活着、以及和 /api/* 区分开。
|
| 如果连这个都不要，把 bootstrap/app.php 里的 web: 参数去掉即可，
| / 会直接 404，服务的唯一入口就是 /api/*。
|
*/

Route::get('/', fn () => ApiResponse::ok([
    'service' => 'nmnx-core',
    'mode' => 'api-only',
    'health' => '/api/v1/health',
]));
