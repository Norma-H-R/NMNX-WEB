<?php

declare(strict_types=1);

/**
 * RbacApiTest —— 权限点与角色接口的对外契约
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块测试。
 * 用途：把 `/api/v1/admin/permissions*` 与 `/roles*` 的行为钉死 ——
 *       这批接口是 admin 前端那个页面**直接吃**的东西，字段名和状态码都不能随手改。
 * 谁在调：`php artisan test`。
 *
 * 覆盖的边界：
 *   - 鉴权：没带管理员令牌一律 401（这几条路由在 `auth:admin` 组里）
 *   - 读：目录 14 组 / 121 个、角色 5 个 + 配色选项
 *   - 权限点：新增（含格式/重复/分组校验）、改名（**引用不需要同步**）、删除（级联清引用）
 *   - 角色：新增（key 后端生成）、改基线（全量替换 / 未知权限点拒绝 / owner 拒绝）、
 *           删除（先把人转成 member）
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/08-rbac.md
 */

namespace Tests\Feature\Rbac;

use App\Models\User;
use App\Modules\Auth\Models\Admin;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Services\PermissionRegistry;
use App\Modules\Support\ErrorCode;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\ApiTestCase;

final class RbacApiTest extends ApiTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /** 场景：没带管理员令牌不能读权限目录 */
    public function test_requires_admin_token(): void
    {
        $this->assertApiError($this->getJson('/api/v1/admin/permissions'), ErrorCode::SYS_UNAUTHENTICATED);
        $this->assertApiError($this->getJson('/api/v1/admin/roles'), ErrorCode::SYS_UNAUTHENTICATED);
    }

    /** 场景：目录接口吐出 14 组 / 121 个，且每条都带 id（前端编辑要用） */
    public function test_permission_index(): void
    {
        $response = $this->getJson('/api/v1/admin/permissions', $this->adminHeaders());

        $this->assertApiOk($response);

        $this->assertCount(14, $response->json('data.groups'));
        $this->assertCount(121, $response->json('data.keys'));

        $first = $response->json('data.groups.0.items.0');
        $this->assertSame('user.read', $first['key']);
        $this->assertIsInt($first['id']);
        $this->assertFalse($first['custom']);
    }

    /** 场景：角色接口吐出 5 个角色 + 配色选项；owner 的权限已展开成全部 */
    public function test_role_index(): void
    {
        $response = $this->getJson('/api/v1/admin/roles', $this->adminHeaders());

        $this->assertApiOk($response);
        $this->assertCount(5, $response->json('data.roles'));
        $this->assertCount(6, $response->json('data.tones'));

        $roles = collect($response->json('data.roles'))->keyBy('key');
        $this->assertTrue($roles['owner']['grants_all']);
        $this->assertCount(121, $roles['owner']['permissions']);
        $this->assertCount(19, $roles['admin']['permissions']);
    }

    /** 场景：新增权限点后**目录立刻能看到**（写完 flush 了缓存） */
    public function test_store_permission_flushes_cache(): void
    {
        $response = $this->postJson('/api/v1/admin/permissions', [
            'key' => 'media.alert',
            'label' => '告警规则',
            'description' => '配置自媒体告警',
            'group_title' => '自媒体',
        ], $this->adminHeaders());

        $this->assertApiOk($response, ['permission.key' => 'media.alert', 'permission.custom' => true]);
        $this->assertDatabaseHas('permissions', ['key' => 'media.alert', 'is_builtin' => 0]);

        // 关键断言：再读一次目录，必须**已经包含**它（没 flush 的话这里还是 121）
        $catalog = $this->getJson('/api/v1/admin/permissions', $this->adminHeaders());
        $this->assertContains('media.alert', $catalog->json('data.keys'));
        $this->assertCount(122, $catalog->json('data.keys'));
    }

    /** 场景：标识格式不对 / 重复 / 分组不存在，都被拒绝 */
    public function test_store_permission_rejects_bad_input(): void
    {
        $this->assertApiError($this->postJson('/api/v1/admin/permissions', [
            'key' => 'Bad Key',
            'label' => '乱写的',
            'group_title' => '自媒体',
        ], $this->adminHeaders()), ErrorCode::SYS_VALIDATION);

        $this->assertApiError($this->postJson('/api/v1/admin/permissions', [
            'key' => 'user.read',
            'label' => '抄一个已有的',
            'group_title' => '用户管理',
        ], $this->adminHeaders()), ErrorCode::SYS_VALIDATION);

        $this->assertApiError($this->postJson('/api/v1/admin/permissions', [
            'key' => 'nope.thing',
            'label' => '不存在的分组',
            'group_title' => '不存在的分组',
        ], $this->adminHeaders()), ErrorCode::SYS_VALIDATION);
    }

    /**
     * 场景：**改权限点的 key，角色基线自动跟着改** —— 不需要同步任何引用。
     *
     * 这是"pivot 存 ID 不存 key"最直接的回报：前端那套 renamePermissionRefs
     * 在我们这里根本不存在，也不会漏。
     */
    public function test_rename_permission_keeps_role_baseline(): void
    {
        app(PermissionRegistry::class)->flush();

        $permission = Permission::query()->where('key', 'forum.post.pin')->firstOrFail();
        $moderator = Role::query()->where('key', 'moderator')->firstOrFail();

        $this->assertTrue($moderator->permissions()->whereKey($permission->getKey())->exists());

        $this->assertApiOk($this->patchJson(
            '/api/v1/admin/permissions/'.$permission->getKey(),
            ['key' => 'forum.post.sticky'],
            $this->adminHeaders(),
        ), ['permission.key' => 'forum.post.sticky']);

        // moderator 的基线里，那条权限点的 **id 没变**，只是 key 变了
        $this->assertTrue(
            Role::query()->where('key', 'moderator')->firstOrFail()
                ->permissions()->whereKey($permission->getKey())->exists(),
        );

        $roleKeys = app(PermissionRegistry::class)->permissionsOfRole('moderator');
        $this->assertContains('forum.post.sticky', $roleKeys);
        $this->assertNotContains('forum.post.pin', $roleKeys);
    }

    /** 场景：删权限点会把角色基线里的引用一起清掉（外键级联） */
    public function test_delete_permission_purges_references(): void
    {
        $permission = Permission::query()->where('key', 'forum.post.delete')->firstOrFail();

        $this->assertTrue(Role::query()->where('key', 'admin')->firstOrFail()
            ->permissions()->whereKey($permission->getKey())->exists());

        $this->assertApiOk($this->deleteJson('/api/v1/admin/permissions/'.$permission->getKey(), [], $this->adminHeaders()));

        $this->assertDatabaseMissing('permissions', ['key' => 'forum.post.delete']);
        $this->assertDatabaseMissing('role_permissions', ['permission_id' => $permission->getKey()]);
    }

    /** 场景：新增角色 —— key 由后端生成 */
    public function test_store_role_generates_key(): void
    {
        $response = $this->postJson('/api/v1/admin/roles', [
            'name' => '论坛运营',
            'description' => '只置顶加精',
            'tone' => 'violet',
            'permissions' => ['forum.read', 'forum.post.pin'],
        ], $this->adminHeaders());

        $this->assertApiOk($response, [
            'role.key' => 'custom-1',
            'role.name' => '论坛运营',
            'role.grants_all' => false,
        ]);
        $this->assertCount(2, $response->json('data.role.permissions'));
    }

    /** 场景：改角色的基线是全量替换；未知权限点整体拒绝；owner 不许改 */
    public function test_update_role_baseline(): void
    {
        $role = Role::query()->where('key', 'blogger')->firstOrFail();

        $this->assertApiOk($this->patchJson(
            '/api/v1/admin/roles/'.$role->getKey(),
            ['permissions' => ['forum.read']],
            $this->adminHeaders(),
        ), ['role.key' => 'blogger']);

        $this->assertSame(['forum.read'], app(PermissionRegistry::class)->permissionsOfRole('blogger'));

        // 未知权限点：整体拒绝，不做"能识别几个算几个"
        $this->assertApiError($this->patchJson(
            '/api/v1/admin/roles/'.$role->getKey(),
            ['permissions' => ['forum.read', 'nope.thing']],
            $this->adminHeaders(),
        ), ErrorCode::SYS_VALIDATION);

        $this->assertSame(['forum.read'], app(PermissionRegistry::class)->permissionsOfRole('blogger'));

        // owner 隐含全部，不许单独配基线
        $owner = Role::query()->where('key', 'owner')->firstOrFail();
        $this->assertApiError($this->patchJson(
            '/api/v1/admin/roles/'.$owner->getKey(),
            ['permissions' => ['forum.read']],
            $this->adminHeaders(),
        ), ErrorCode::SYS_VALIDATION);
    }

    /** 场景：删角色时**先把人转成 member**，再删；系统依赖不许删 */
    public function test_delete_role_moves_users_first(): void
    {
        $blogger = Role::query()->where('key', 'blogger')->firstOrFail();
        $member = Role::query()->where('key', 'member')->firstOrFail();

        $user = User::factory()->create(['role_id' => $blogger->getKey()]);

        $this->assertApiOk(
            $this->deleteJson('/api/v1/admin/roles/'.$blogger->getKey(), [], $this->adminHeaders()),
            ['moved_users' => 1],
        );

        $this->assertDatabaseMissing('roles', ['key' => 'blogger']);
        $this->assertSame($member->getKey(), $user->fresh()->role_id);

        // member 是默认身份，不许删
        $this->assertApiError(
            $this->deleteJson('/api/v1/admin/roles/'.$member->getKey(), [], $this->adminHeaders()),
            ErrorCode::SYS_VALIDATION,
        );
    }

    /**
     * 造一个管理员令牌的请求头。
     *
     * @return array<string, string> 请求头
     */
    private function adminHeaders(): array
    {
        $admin = Admin::factory()->create();

        return ['Authorization' => 'Bearer '.$admin->createToken('test-device')->plainTextToken];
    }
}
