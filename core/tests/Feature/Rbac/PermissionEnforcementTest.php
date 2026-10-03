<?php

declare(strict_types=1);

/**
 * PermissionEnforcementTest —— 鉴权通道（`permission:` 中间件）的行为
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块测试。
 * 用途：把"权限真的拦得住"钉死。**这是全项目最重要的一组测试** ——
 *       后面每接一个权限点，都照这里的写法补一对「有它→通过 / 没它→403」。
 * 谁在调：`php artisan test`。
 *
 * 覆盖的边界：
 *   - 有权限 → 放行
 *   - **没权限 → 403**，且 message 带权限点中文名（前端靠它弹窗）
 *   - 没登录 → 401（与 403 区分开：一个该重新登录，一个该说缺什么）
 *   - **提权回归**：请求体里伪造 `role` / `permissions` → 照样 403
 *   - 管理员没挂回总表（`user_id` 为空）→ 一律拒绝，不放行任何能力
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/permissions-model.md 第 6.1/6.3 节
 */

namespace Tests\Feature\Rbac;

use App\Models\User;
use App\Modules\Auth\Models\Admin;
use App\Modules\Rbac\Models\Role;
use App\Modules\Support\ErrorCode;
use App\Modules\Support\Http\ApiResponse;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\ApiTestCase;

final class PermissionEnforcementTest extends ApiTestCase
{
    use RefreshDatabase;

    /** 测试用路由的那件事：要 `forum.post.delete`（论坛版主 / 管理员 / 超管才有） */
    private const ABILITY = 'forum.post.delete';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        // 测试专用路由：走**和真实接口同一条鉴权通道**（auth:admin + permission:）
        Route::middleware(['auth:admin', 'permission:'.self::ABILITY])
            ->delete('/api/v1/__test/forum-post', fn () => ApiResponse::ok(['deleted' => true]));
    }

    /** 场景：owner（隐含全部权限）→ 放行 */
    public function test_owner_passes(): void
    {
        $this->assertApiOk(
            $this->deleteJson('/api/v1/__test/forum-post', [], $this->headersFor('owner')),
        );
    }

    /** 场景：版主角色基线里有它 → 放行 */
    public function test_moderator_passes(): void
    {
        $this->assertApiOk(
            $this->deleteJson('/api/v1/__test/forum-post', [], $this->headersFor('moderator')),
        );
    }

    /** 场景：**博主角色没有这个权限 → 403**，且提示带中文名（前端据此弹窗） */
    public function test_blogger_is_forbidden_with_readable_message(): void
    {
        $response = $this->deleteJson('/api/v1/__test/forum-post', [], $this->headersFor('blogger'));

        $this->assertApiError($response, ErrorCode::SYS_FORBIDDEN);

        $this->assertStringContainsString('删除帖子', (string) $response->json('message'));
    }

    /** 场景：没带令牌 → 401（不是 403：该让他重新登录，而不是说"你没权限"） */
    public function test_guest_gets_401(): void
    {
        $this->assertApiError(
            $this->deleteJson('/api/v1/__test/forum-post'),
            ErrorCode::SYS_UNAUTHENTICATED,
        );
    }

    /**
     * 场景（**提权回归**）：请求体里伪造 `role` / `permissions` → **照样 403**。
     *
     * 这是"不可能靠改前端提权"的兜底证明：服务端**从不读**这些字段，
     * 它只认自己库里算出来的（角色基线 ± 个人增减）。
     */
    public function test_forged_role_in_payload_cannot_escalate(): void
    {
        $this->assertApiError(
            $this->deleteJson('/api/v1/__test/forum-post', [
                'role' => 'owner',
                'role_id' => 1,
                'permissions' => [self::ABILITY],
                'is_admin' => true,
                'grants_all' => true,
            ], $this->headersFor('blogger')),
            ErrorCode::SYS_FORBIDDEN,
        );
    }

    /** 场景：管理员没挂回总表（user_id 为空）→ 一律拒绝，不放行任何能力 */
    public function test_admin_without_user_record_is_denied(): void
    {
        $admin = Admin::factory()->create(['user_id' => null]);

        $this->assertApiError(
            $this->deleteJson('/api/v1/__test/forum-post', [], [
                'Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken,
            ]),
            ErrorCode::SYS_FORBIDDEN,
        );
    }

    /** 场景：给这个角色**个人额外授予**该权限 → 立刻放行（个人增减生效） */
    public function test_personal_grant_makes_it_pass(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('key', 'blogger')->value('id'),
        ]);

        $admin = Admin::factory()->create(['user_id' => $user->getKey()]);
        $headers = ['Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken];

        // 先确认基线里没有它
        $this->assertApiError(
            $this->deleteJson('/api/v1/__test/forum-post', [], $headers),
            ErrorCode::SYS_FORBIDDEN,
        );

        // 个人额外授予之后应当放行
        app(\App\Modules\Rbac\Services\RbacService::class)->updateRole(
            Role::query()->where('key', 'blogger')->firstOrFail(),
            ['permissions' => ['blog.read', 'blog.create', 'blog.edit', 'forum.read', self::ABILITY]],
        );

        $this->assertApiOk($this->deleteJson('/api/v1/__test/forum-post', [], $headers));
    }

    /**
     * 场景：`/me/permissions` 下发的是**自己那份**权限（前端拿去控按钮显隐）。
     *
     * 这条路也是"刷新页面后补权限""收到 403 后重拉权限"的实现基础。
     */
    public function test_my_permissions_endpoint_returns_own_baseline(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('key', 'blogger')->value('id'),
        ]);

        $admin = Admin::factory()->create(['user_id' => $user->getKey()]);

        $response = $this->getJson('/api/v1/admin/me/permissions', [
            'Authorization' => 'Bearer '.$admin->createToken('t')->plainTextToken,
        ]);

        $this->assertApiOk($response);

        $this->assertEqualsCanonicalizing(
            ['blog.read', 'blog.create', 'blog.edit', 'forum.read'],
            $response->json('data.permissions'),
        );
    }

    /** 场景：这个接口不能用来查别人的权限（没有"给某人"这种入参），未登录一律 401 */
    public function test_my_permissions_requires_login(): void
    {
        $this->assertApiError(
            $this->getJson('/api/v1/admin/me/permissions'),
            ErrorCode::SYS_UNAUTHENTICATED,
        );
    }

    /**
     * 造一个"挂了角色"的管理员令牌请求头。
     *
     * @param  string  $roleKey  角色 key（owner / admin / moderator / blogger / member）
     * @return array<string, string>  请求头
     */
    private function headersFor(string $roleKey): array
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('key', $roleKey)->value('id'),
        ]);

        $admin = Admin::factory()->create(['user_id' => $user->getKey()]);

        return ['Authorization' => 'Bearer '.$admin->createToken('test-device')->plainTextToken];
    }
}
