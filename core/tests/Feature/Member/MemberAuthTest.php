<?php

declare(strict_types=1);

/**
 * MemberAuthTest —— 会员注册 / 登录 / 登出的完整行为
 *
 * 本文件属于 core（纯 API 后端）的 Member 模块测试。
 * 用途：把会员鉴权接口的**对外契约**钉死。
 * 谁在调：`php artisan test`。
 *
 * 覆盖的边界（与 AdminLoginTest 对齐，会员侧多了注册）：
 *   - 注册成功 / 邮箱重复 / 两次密码不一致
 *   - 登录成功 / 密码错 / 邮箱不存在（同码）/ 账号禁用
 *   - 注册即登录（拿到的令牌能直接访问 me）
 *   - 登出吊销 / 限流
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/07-member.md
 */

namespace Tests\Feature\Member;

use App\Models\User;
use App\Modules\Support\ErrorCode;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\ApiTestCase;

final class MemberAuthTest extends ApiTestCase
{
    use RefreshDatabase;

    /** 场景：邮箱 + 密码注册成功，返回令牌与会员信息，并落库 */
    public function test_register_succeeds(): void
    {
        $response = $this->postJson('/api/v1/member/register', [
            'name' => '张三',
            'email' => 'zhang@example.com',
            'password' => '11111',
            'password_confirmation' => '11111',
        ]);

        $this->assertApiOk($response, [
            'member.email' => 'zhang@example.com',
            'member.status' => 'active',
        ]);
        $this->assertStringContainsString('|', $response->json('data.token'));
        $this->assertDatabaseHas('users', ['email' => 'zhang@example.com']);
    }

    /** 场景：昵称留空时，用邮箱前缀兜底 */
    public function test_register_defaults_name_from_email(): void
    {
        $this->assertApiOk($this->postJson('/api/v1/member/register', [
            'email' => 'someone@example.com',
            'password' => '11111',
            'password_confirmation' => '11111',
        ]), ['member.name' => 'someone']);
    }

    /**
     * 场景：注册出来的会员自动挂 **member 角色**。
     *
     * 这条很关键：没有角色的人**一个权限都没有**（连"发帖""投稿"这类前台能力
     * 也要靠角色给），注册时忘了挂角色，新用户在前台会处处受限。
     */
    public function test_register_assigns_default_role(): void
    {
        $this->seed(RbacSeeder::class);

        $this->postJson('/api/v1/member/register', [
            'email' => 'role@example.com',
            'password' => '11111',
            'password_confirmation' => '11111',
        ]);

        $user = User::query()->where('email', 'role@example.com')->firstOrFail();

        $this->assertNotNull($user->role_id);
        $this->assertSame('member', $user->role->key);
    }

    /** 场景：邮箱重复 → 422，明细在 meta.errors.email */
    public function test_register_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'dup@example.com']);

        $response = $this->postJson('/api/v1/member/register', [
            'email' => 'dup@example.com',
            'password' => '11111',
            'password_confirmation' => '11111',
        ]);

        $this->assertApiError($response, ErrorCode::SYS_VALIDATION);
        $response->assertJsonPath('meta.errors.email', fn ($v) => is_array($v) && $v !== []);
    }

    /** 场景：两次密码不一致 → 422，明细在 meta.errors.password */
    public function test_register_rejects_mismatched_password(): void
    {
        $response = $this->postJson('/api/v1/member/register', [
            'email' => 'mismatch@example.com',
            'password' => '11111',
            'password_confirmation' => '22222',
        ]);

        $this->assertApiError($response, ErrorCode::SYS_VALIDATION);
        $response->assertJsonPath('meta.errors.password', fn ($v) => is_array($v) && $v !== []);
    }

    /** 场景：登录成功签发令牌 */
    public function test_login_succeeds(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/v1/member/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertApiOk($response, ['member.id' => $user->id, 'member.email' => $user->email]);
    }

    /** 场景：用**手机号**登录（前端"手机号"方式；测试期统一填 11111，不做数字校验） */
    public function test_login_with_phone(): void
    {
        $user = User::factory()->create(['phone' => '11111']);

        $this->assertApiOk(
            $this->postJson('/api/v1/member/login', ['phone' => '11111', 'password' => 'password']),
            ['member.id' => $user->id, 'member.phone' => '11111'],
        );
    }

    /** 场景：邮箱和手机号都不给 → 422（required_without 兜住） */
    public function test_login_requires_an_identifier(): void
    {
        $response = $this->postJson('/api/v1/member/login', ['password' => '11111']);

        $this->assertApiError($response, ErrorCode::SYS_VALIDATION);
    }

    /** 场景：密码错误 → 401 AUTH_INVALID_CREDENTIALS */
    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create();

        $this->assertApiError(
            $this->postJson('/api/v1/member/login', ['email' => $user->email, 'password' => 'wrong']),
            ErrorCode::AUTH_INVALID_CREDENTIALS,
        );
    }

    /** 场景：邮箱不存在 → 与密码错同一个错误码（防枚举） */
    public function test_unknown_email_returns_same_code_as_wrong_password(): void
    {
        $this->assertApiError(
            $this->postJson('/api/v1/member/login', ['email' => 'nobody@example.com', 'password' => 'x']),
            ErrorCode::AUTH_INVALID_CREDENTIALS,
        );
    }

    /** 场景：**被禁言**的会员仍然能登录（禁言 ≠ 封号：还能看自己的授权码） */
    public function test_muted_member_can_still_login(): void
    {
        $user = User::factory()->muted()->create();

        $this->assertApiOk(
            $this->postJson('/api/v1/member/login', ['email' => $user->email, 'password' => 'password']),
            ['member.id' => $user->id, 'member.status' => 'muted'],
        );
    }

    /** 场景：被封禁的会员即使密码正确也登录失败 */
    public function test_banned_member_cannot_login(): void
    {
        $user = User::factory()->banned()->create();

        $this->assertApiError(
            $this->postJson('/api/v1/member/login', ['email' => $user->email, 'password' => 'password']),
            ErrorCode::AUTH_ACCOUNT_DISABLED,
        );
    }

    /** 场景：注册拿到的令牌能直接访问 /me（注册即登录） */
    public function test_token_from_register_can_access_me(): void
    {
        $token = $this->postJson('/api/v1/member/register', [
            'email' => 'auto@example.com',
            'password' => '11111',
            'password_confirmation' => '11111',
        ])->json('data.token');

        $this->assertApiOk(
            $this->getJson('/api/v1/member/me', ['Authorization' => 'Bearer '.$token]),
            ['member.email' => 'auto@example.com'],
        );
    }

    /** 场景：登出之后同一个令牌立即失效 */
    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $token = $this->postJson('/api/v1/member/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('data.token');

        $headers = ['Authorization' => 'Bearer '.$token];

        $this->assertApiOk($this->postJson('/api/v1/member/logout', [], $headers));
        $this->assertNull(PersonalAccessToken::findToken($token));

        $this->forgetAuthenticatedUser();
        $this->assertApiError($this->getJson('/api/v1/member/me', $headers), ErrorCode::SYS_UNAUTHENTICATED);
    }

    /** 场景：同邮箱同 IP 连续试错超过阈值后返回 429 */
    public function test_login_is_rate_limited(): void
    {
        $payload = ['email' => 'brute@example.com', 'password' => 'guess'];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->assertApiError(
                $this->postJson('/api/v1/member/login', $payload),
                ErrorCode::AUTH_INVALID_CREDENTIALS,
            );
        }

        $this->assertApiError(
            $this->postJson('/api/v1/member/login', $payload),
            ErrorCode::SYS_RATE_LIMITED,
        );
    }

    /**
     * 场景：限流按「登录标识 + IP」分桶 —— 换一个手机号不该被前一个的失败次数牵连。
     *
     * 这是回归测试：限流 key 曾经只取 `email`，而手机号登录没有 email，
     * key 退化成 `'|IP'`，于是**所有手机号共用同一个桶**（换成 22222 也会被 429）。
     */
    public function test_rate_limit_is_per_identifier(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->assertApiError(
                $this->postJson('/api/v1/member/login', ['phone' => '11111', 'password' => 'guess']),
                ErrorCode::AUTH_INVALID_CREDENTIALS,
            );
        }

        $this->assertApiError(
            $this->postJson('/api/v1/member/login', ['phone' => '11111', 'password' => 'guess']),
            ErrorCode::SYS_RATE_LIMITED,
        );

        // 换一个手机号：仍然是"凭据错误"，而不是 429
        $this->assertApiError(
            $this->postJson('/api/v1/member/login', ['phone' => '22222', 'password' => 'guess']),
            ErrorCode::AUTH_INVALID_CREDENTIALS,
        );
    }
}
