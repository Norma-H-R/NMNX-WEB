<?php

declare(strict_types=1);

/**
 * RbacRegistryTest —— 权限目录 / 角色基线 / 预加载的行为
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块测试。
 * 用途：把 Rbac 的**对外契约**钉死：目录条目数、角色基线、个人增减的算法、
 *       以及"预加载缓存什么时候该失效"。
 * 谁在调：`php artisan test`。
 *
 * 覆盖的边界：
 *   - 目录：14 组 / 121 个，且**前端契约里的 key 逐字存在**（页面靠它渲染）
 *   - 角色：5 个内置；owner 隐含全部（不存快照）；admin 基线 19；member 基线为空
 *   - 个人增减：granted=true 加进来、false 从基线里收回
 *   - 预加载：请求内记忆 + 显式 flush 之后才看到"绕过服务改的表"
 *   - 单例绑定：同一个实例，记忆才有效
 *   - 公开 ID：创建用户时自动分配
 *   - 角色还在被人用时**删不掉**（数据库层挡，不靠调用方自觉）
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/08-rbac.md
 */

namespace Tests\Feature\Rbac;

use App\Models\User;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Models\UserPermission;
use App\Modules\Rbac\Services\PermissionRegistry;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RbacRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /** 场景：权限目录按契约铺满（14 组 / 121 个），且前端用的 key 逐字存在 */
    public function test_catalog_is_seeded(): void
    {
        $registry = app(PermissionRegistry::class);

        $this->assertCount(14, $registry->catalog());
        $this->assertCount(121, $registry->allKeys());

        // 抽查：这几个 key 是 admin 前端页面直接引用的，少一个页面就少一行
        foreach (['user.read', 'forum.post.delete', 'license.batch', 'system.danger'] as $key) {
            $this->assertTrue($registry->has($key), "权限点 [$key] 应当存在");
        }

        // 分组标题也要对得上（前端按标题分块）
        $this->assertContains('用户管理', array_column($registry->catalog(), 'title'));
        $this->assertContains('密钥', array_column($registry->catalog(), 'title'));
    }

    /** 场景：5 个内置角色；owner 隐含全部权限且**不存快照** */
    public function test_owner_grants_all_without_snapshot(): void
    {
        $registry = app(PermissionRegistry::class);
        $roles = collect($registry->roles())->keyBy('key');

        $this->assertCount(5, $roles);
        $this->assertTrue($roles['owner']['grants_all']);
        $this->assertCount(count($registry->allKeys()), $roles['owner']['permissions']);

        // owner 的 pivot 必须是空的 —— 存了快照，后续新增权限点就会"过期"
        $this->assertSame(
            0,
            UserPermission::query()->count() + Role::query()->where('key', 'owner')->firstOrFail()->permissions()->count(),
        );
    }

    /** 场景：admin 的基线数量与前端 mock 一致（19 个），member 为空 */
    public function test_builtin_role_baselines(): void
    {
        $registry = app(PermissionRegistry::class);

        $this->assertCount(19, $registry->permissionsOfRole('admin'));
        $this->assertCount(16, $registry->permissionsOfRole('moderator'));
        $this->assertCount(4, $registry->permissionsOfRole('blogger'));
        $this->assertSame([], $registry->permissionsOfRole('member'));
        $this->assertSame([], $registry->permissionsOfRole('不存在的角色'));
    }

    /** 场景：个人增减在角色基线上生效 —— 加得进来、也收得回去 */
    public function test_effective_permissions_apply_overrides(): void
    {
        $registry = app(PermissionRegistry::class);
        $user = User::factory()->create([
            'role_id' => Role::query()->where('key', 'blogger')->value('id'),
        ]);

        // blogger 基线是 4 个
        $this->assertCount(4, $registry->effectiveFor($user));

        UserPermission::query()->create([
            'user_id' => $user->getKey(),
            'permission_id' => $this->permissionId('user.delete'),
            'granted' => true,
        ]);
        UserPermission::query()->create([
            'user_id' => $user->getKey(),
            'permission_id' => $this->permissionId('blog.read'),
            'granted' => false,
        ]);

        $effective = app(PermissionRegistry::class)->effectiveFor($user->fresh());

        $this->assertContains('user.delete', $effective);
        $this->assertNotContains('blog.read', $effective);
        $this->assertCount(4, $effective);
    }

    /** 场景：没有角色的用户不会崩，只拿个人增减 */
    public function test_user_without_role_is_safe(): void
    {
        $user = User::factory()->create(['role_id' => null]);

        $this->assertSame([], app(PermissionRegistry::class)->effectiveFor($user));
    }

    /**
     * 场景：预加载**故意会读到旧数据**，直到显式 flush。
     *
     * 这条既是测试也是文档 —— 说明"预加载"的代价：绕过服务直接改表不会立刻生效。
     * 所以要写权限目录，**必须走服务或 Seeder**（它们都会 flush）。
     */
    public function test_registry_serves_cache_until_flushed(): void
    {
        $registry = app(PermissionRegistry::class);

        $this->assertTrue($registry->has('user.read'));

        // 绕过服务直接删（模拟"有人手工改表"）
        Permission::query()->where('key', 'user.read')->delete();

        // 请求内记忆还在，所以仍然"看得见"——这正是预加载的含义
        $this->assertTrue($registry->has('user.read'));

        // flush 之后才看到真相
        $registry->flush();
        $this->assertFalse(app(PermissionRegistry::class)->has('user.read'));
    }

    /** 场景：注册表是容器单例（不同实例的话"请求内记忆"等于没有） */
    public function test_registry_is_a_singleton(): void
    {
        $this->assertSame(
            app(PermissionRegistry::class),
            app(PermissionRegistry::class),
        );
    }

    /** 场景：新建用户自动分配公开 ID，且不与自增 id 混淆 */
    public function test_public_id_is_assigned_on_create(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->public_id);
        $this->assertSame(
            User::PUBLIC_ID_PREFIX.str_pad((string) $user->getKey(), 6, '0', STR_PAD_LEFT),
            $user->fresh()->public_id,
        );
    }

    /** 场景：还有人在用的角色**删不掉** —— 由数据库外键挡，不靠调用方自觉 */
    public function test_role_in_use_cannot_be_deleted(): void
    {
        $role = Role::query()->where('key', 'member')->firstOrFail();

        User::factory()->create(['role_id' => $role->getKey()]);

        $this->expectException(QueryException::class);

        $role->delete();
    }

    /**
     * 取权限点的 ID（测试内部用）。
     *
     * @param  string  $key  能力名
     * @return int 权限点 ID
     */
    private function permissionId(string $key): int
    {
        return Permission::query()->where('key', $key)->value('id');
    }
}
