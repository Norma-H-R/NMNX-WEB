<?php

declare(strict_types=1);

/**
 * GuardIsolationTest —— 守卫主体隔离（越权防线）
 *
 * 本文件属于 core（纯 API 后端）的 Auth 模块测试。
 * 用途：钉死一条安全底线 —— **前台会员的令牌绝不能访问后台管理接口**。
 *       需求 D1 要求会员与管理者是两套人（`users` / `admins` 两张表），
 *       这个测试就是那条要求的可执行版本。
 * 谁在调：`php artisan test`。
 *
 * 为什么必须有这个测试：
 *   Sanctum 的 guard 在解析令牌后**直接返回 tokenable，并不比对 provider**。
 *   也就是说，只在 `config/auth.php` 里配好 `admin` guard 是**不够的** ——
 *   必须有额外的拦截。这个测试就是用来证明"拦截真的存在且生效"，
 *   以后有人重构中间件时它会立刻变红。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/01-auth.md
 */

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\Admin;
use App\Modules\Support\ErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\ApiTestCase;

final class GuardIsolationTest extends ApiTestCase
{
    use RefreshDatabase;

    /** 场景：管理员令牌能正常访问受 admin 守卫保护的接口 */
    public function test_admin_token_passes_admin_guard(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('test-device')->plainTextToken;

        $response = $this->getJson('/api/v1/admin/me', [
            'Authorization' => 'Bearer '.$token,
        ]);

        // 出参形态是 data.admin.{…}（与登录接口的 data.admin 保持一致）
        $this->assertApiOk($response, ['admin.id' => $admin->id, 'admin.username' => $admin->username]);
    }

    /** 场景：前台会员的令牌**不能**访问后台接口（越权防线） */
    public function test_member_token_cannot_pass_admin_guard(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-device')->plainTextToken;

        $response = $this->getJson('/api/v1/admin/me', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $this->assertApiError($response, ErrorCode::SYS_UNAUTHENTICATED);
    }

    /** 场景：管理员令牌**不能**访问会员接口（反向越权防线） */
    public function test_admin_token_cannot_pass_member_guard(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->createToken('test-device')->plainTextToken;

        $response = $this->getJson('/api/v1/member/me', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $this->assertApiError($response, ErrorCode::SYS_UNAUTHENTICATED);
    }

    /** 场景：伪造的令牌被拒绝 */
    public function test_bogus_token_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/admin/me', [
            'Authorization' => 'Bearer 1|totally-made-up-token',
        ]);

        $this->assertApiError($response, ErrorCode::SYS_UNAUTHENTICATED);
    }
}
