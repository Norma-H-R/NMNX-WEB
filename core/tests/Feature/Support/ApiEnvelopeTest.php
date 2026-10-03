<?php

declare(strict_types=1);

/**
 * ApiEnvelopeTest —— 统一响应体的端到端断言
 *
 * 本文件属于 core（纯 API 后端）的 Support 模块测试。
 * 用途：把"经 bootstrap/app.php 全局异常映射之后的真实 HTTP 出参"钉死。
 *       它同时是 `Tests\ApiTestCase` 用法的活样本。
 * 谁在调：`php artisan test`。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/00-support.md
 */

namespace Tests\Feature\Support;

use App\Modules\Support\ErrorCode;
use Tests\ApiTestCase;

final class ApiEnvelopeTest extends ApiTestCase
{
    /** 场景：探针接口返回成功响应体，且 data 里带着服务名与 API 版本 */
    public function test_health_returns_ok_envelope(): void
    {
        $this->assertApiOk($this->getJson('/api/v1/health'), [
            'service' => 'nmnx-core',
            'api' => 'v1',
        ]);
    }

    /** 场景：不存在的路由被全局异常映射成 SYS_NOT_FOUND（不是框架默认的 404 HTML/结构） */
    public function test_unknown_route_maps_to_not_found(): void
    {
        $this->assertApiError($this->getJson('/api/v1/definitely-not-a-route'), ErrorCode::SYS_NOT_FOUND);
    }

    /** 场景：方法不允许时映射成 SYS_METHOD_NOT_ALLOWED，而不是漏到 SYS_INTERNAL */
    public function test_wrong_method_maps_to_method_not_allowed(): void
    {
        $this->assertApiError($this->deleteJson('/api/v1/health'), ErrorCode::SYS_METHOD_NOT_ALLOWED);
    }

    /** 场景：未带令牌访问受保护接口，得到 SYS_UNAUTHENTICATED 而不是重定向或 500 */
    public function test_unauthenticated_maps_to_unauthenticated(): void
    {
        $this->assertApiError($this->getJson('/api/v1/admin/me'), ErrorCode::SYS_UNAUTHENTICATED);
    }
}
