<?php

declare(strict_types=1);

/**
 * AdminLoginTest —— 后台登录 / 登出的完整行为
 *
 * 本文件属于 core（纯 API 后端）的 Auth 模块测试。
 * 用途：把登录接口的**对外契约**钉死 —— 成功出什么、失败出什么错误码、
 *       令牌能不能用、登出之后还能不能用、试太多次会不会被限流。
 * 谁在调：`php artisan test`。
 *
 * 覆盖的边界（每一条都对应真会被踩到的坑）：
 *   - 密码错 / 账号不存在 → **同一个错误码**（防账号枚举）
 *   - 账号被禁用 → 密码对也不给进
 *   - 限流 → 第 6 次是 429，不是继续 401
 *   - 登出只吊销当前设备，别处的令牌不受影响
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/01-auth.md
 */

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\Admin;
use App\Modules\Support\ErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\ApiTestCase;

final class AdminLoginTest extends ApiTestCase
{
    use RefreshDatabase;

    /** 场景：账号密码正确时签发令牌，并返回管理员信息 */
    public function test_login_succeeds_with_correct_credentials(): void
    {
        $admin = Admin::factory()->create();

        $response = $this->postJson('/api/v1/admin/login', [
            'username' => $admin->username,
            'password' => 'password',
            'device_name' => 'phpunit',
        ]);

        $this->assertApiOk($response, [
            'admin.username' => $admin->username,
            'admin.status' => 'active',
            'token' => fn ($value) => is_string($value) && $value !== '',
        ]);
    }

    /** 场景：登录成功时记录最后登录时间与 IP */
    public function test_login_records_last_login(): void
    {
        $admin = Admin::factory()->create();
        $this->assertNull($admin->last_login_at);

        $this->assertApiOk($this->postJson('/api/v1/admin/login', [
            'username' => $admin->username,
            'password' => 'password',
        ]));

        $fresh = $admin->fresh();
        $this->assertNotNull($fresh->last_login_at);
        $this->assertNotNull($fresh->last_login_ip);
    }

    /** 场景：密码错误时返回 AUTH_INVALID_CREDENTIALS */
    public function test_login_fails_with_wrong_password(): void
    {
        $admin = Admin::factory()->create();

        $this->assertApiError(
            $this->postJson('/api/v1/admin/login', [
                'username' => $admin->username,
                'password' => 'not-the-password',
            ]),
            ErrorCode::AUTH_INVALID_CREDENTIALS,
        );
    }

    /** 场景：账号不存在时返回**与密码错误相同**的错误码（防账号枚举） */
    public function test_unknown_username_returns_same_code_as_wrong_password(): void
    {
        $this->assertApiError(
            $this->postJson('/api/v1/admin/login', [
                'username' => 'definitely-not-someone',
                'password' => 'whatever',
            ]),
            ErrorCode::AUTH_INVALID_CREDENTIALS,
        );
    }

    /** 场景：账号被禁用时，即使密码正确也不签发令牌 */
    public function test_disabled_admin_cannot_login(): void
    {
        $admin = Admin::factory()->disabled()->create();

        $this->assertApiError(
            $this->postJson('/api/v1/admin/login', [
                'username' => $admin->username,
                'password' => 'password',
            ]),
            ErrorCode::AUTH_ACCOUNT_DISABLED,
        );
    }

    /** 场景：缺少字段时返回 SYS_VALIDATION，明细落在 meta.errors */
    public function test_missing_fields_return_validation_errors(): void
    {
        $response = $this->postJson('/api/v1/admin/login', ['username' => '']);

        $this->assertApiError($response, ErrorCode::SYS_VALIDATION);
        $response->assertJsonPath('meta.errors.username', fn ($v) => is_array($v) && $v !== []);
        $response->assertJsonPath('meta.errors.password', fn ($v) => is_array($v) && $v !== []);
    }

    /** 场景：登录拿到的令牌可以直接访问 /me */
    public function test_token_from_login_can_access_me(): void
    {
        $admin = Admin::factory()->create();
        $token = $this->postJson('/api/v1/admin/login', [
            'username' => $admin->username,
            'password' => 'password',
        ])->json('data.token');

        $this->assertApiOk(
            $this->getJson('/api/v1/admin/me', ['Authorization' => 'Bearer '.$token]),
            ['admin.username' => $admin->username, 'admin.id' => $admin->id],
        );
    }

    /** 场景：登出之后同一个令牌立即失效（库里那行也真的没了） */
    public function test_logout_revokes_current_token(): void
    {
        $admin = Admin::factory()->create();
        $token = $this->postJson('/api/v1/admin/login', [
            'username' => $admin->username,
            'password' => 'password',
        ])->json('data.token');

        $headers = ['Authorization' => 'Bearer '.$token];

        $this->assertApiOk($this->postJson('/api/v1/admin/logout', [], $headers));

        // 直接证据：令牌记录已经从库里删掉
        $this->assertNull(PersonalAccessToken::findToken($token));
        $this->assertSame(0, PersonalAccessToken::query()->count());

        // 端到端证据：清掉守卫缓存的登录态之后再访问，必须 401
        // （不调 forgetAuthenticatedUser() 会测出假结果，见 ApiTestCase 里的说明）
        $this->forgetAuthenticatedUser();
        $this->assertApiError($this->getJson('/api/v1/admin/me', $headers), ErrorCode::SYS_UNAUTHENTICATED);
    }

    /** 场景：登出只吊销当前设备，另一台设备的令牌仍然可用 */
    public function test_logout_does_not_revoke_other_devices(): void
    {
        $admin = Admin::factory()->create();

        $deviceA = $admin->createToken('device-a')->plainTextToken;
        $deviceB = $admin->createToken('device-b')->plainTextToken;

        $this->assertApiOk($this->postJson('/api/v1/admin/logout', [], [
            'Authorization' => 'Bearer '.$deviceA,
        ]));

        $this->assertNull(PersonalAccessToken::findToken($deviceA), 'A 设备的令牌应当已注销');
        $this->assertNotNull(PersonalAccessToken::findToken($deviceB), 'B 设备的令牌不该受影响');

        $this->forgetAuthenticatedUser();
        $this->assertApiError(
            $this->getJson('/api/v1/admin/me', ['Authorization' => 'Bearer '.$deviceA]),
            ErrorCode::SYS_UNAUTHENTICATED,
        );

        $this->forgetAuthenticatedUser();
        $this->assertApiOk(
            $this->getJson('/api/v1/admin/me', ['Authorization' => 'Bearer '.$deviceB]),
            ['admin.id' => $admin->id],
        );
    }

    /** 场景：同一账号 + 同一 IP 连续试错超过阈值后返回 SYS_RATE_LIMITED（429） */
    public function test_login_is_rate_limited(): void
    {
        $payload = ['username' => 'brute-force-target', 'password' => 'guess'];

        // 阈值是 5 次/分钟，前 5 次仍然按"凭据错误"返回
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->assertApiError(
                $this->postJson('/api/v1/admin/login', $payload),
                ErrorCode::AUTH_INVALID_CREDENTIALS,
            );
        }

        // 第 6 次被限流挡住 —— 注意错误码是 429 而不是继续 401
        $this->assertApiError(
            $this->postJson('/api/v1/admin/login', $payload),
            ErrorCode::SYS_RATE_LIMITED,
        );
    }
}
