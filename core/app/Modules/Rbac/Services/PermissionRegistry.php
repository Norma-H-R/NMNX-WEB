<?php

declare(strict_types=1);

/**
 * PermissionRegistry —— 权限目录与角色基线的**预加载**层
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块。
 * 用途：鉴权要读"这个角色有哪些权限"，而这件事**每个请求都要做**。
 *       直接查库的话，每个请求都要 join 三张表 —— 那就应了"不然肯定会非常慢"。
 *       所以这里做两层缓存：
 *         ① 请求内记忆（实例属性）—— 一次请求最多 load 一次
 *         ② 跨请求缓存（Cache 门面）—— 命中时只是一次 cache 读，不碰业务表
 * 谁在调：鉴权中间件 / `can()` 判定、角色与权限目录接口、RbacSeeder。
 *
 * ⚠️ 写权限目录或角色基线之后**必须 `flush()`**，否则接口还在读旧目录。
 *    `RbacService` 的写方法内部已统一调用；Seeder 也是。
 *
 * 为什么用容器单例而不是静态属性：
 *   静态属性在**同一进程**里跨测试、跨请求都不重置。单例随容器（= 每个请求/每个测试）
 *   重新构造，记忆自然清空，不会串数据。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/08-rbac.md
 */

namespace App\Modules\Rbac\Services;

use App\Models\User;
use App\Modules\Auth\Models\Admin;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Models\UserPermission;
use Illuminate\Support\Facades\Cache;

final class PermissionRegistry
{
    /**
     * 缓存键。**改了数据结构就要升版本号**（v1 → v2 → v3），否则老结构会被读出来当新结构用。
     *
     * v2：权限点与角色都补了 `id` —— 接口要靠它做 `PATCH /permissions/{id}` 这类路由。
     * v3：角色补了 `users_count` —— 后台角色列表的「N 人」由数据库真算（原来是前端假数据）。
     */
    private const CACHE_KEY = 'nmnx.rbac.registry.v3';

    /**
     * 缓存有效期（秒）。
     *
     * 一天只是**兜底**：正常路径下每次写操作都会 `flush()`，轮不到它过期。
     * 之所以还给个上限，是防"有人绕过服务直接改表"——那种情况缓存不会失效，
     * 有个上限至少不至于错到天荒地老。
     */
    private const TTL = 86400;

    /**
     * 请求内的记忆。null = 还没加载过。
     *
     * @var array{catalog: array<int, mixed>, roles: array<int, mixed>, keys: array<int, string>, role_keys: array<string, array<int, string>>}|null
     */
    private ?array $data = null;

    // ── 对外读取 ──────────────────────────────────────────────────────────

    /**
     * 权限目录（按分组组织，后台的权限点管理页直接渲染它）。
     *
     * 用法：
     *   $groups = app(PermissionRegistry::class)->catalog();
     *   // [ ['title' => '用户管理', 'items' => [ ['key' => 'user.read', 'label' => '查看用户', 'desc' => '…', 'custom' => false], …] ], … ]
     *
     * 边界/注意：
     *   返回的 `custom` 是 `is_builtin` 的**取反** —— 前端契约里叫 custom，
     *   语义是"界面上新增的"，这里做一次翻译，别让前端猜。
     *
     * @return array<int, array{title: string, items: array<int, array{key: string, label: string, desc: string, custom: bool}>}> 分组后的目录
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function catalog(): array
    {
        return $this->data()['catalog'];
    }

    /**
     * 全部权限点的 key。
     *
     * 用法：
     *   in_array($key, $registry->allKeys(), true);   // 校验前端传来的权限名是否合法
     *
     * @return array<int, string> 全部能力名
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function allKeys(): array
    {
        return $this->data()['keys'];
    }

    /**
     * 角色清单（含各自的默认权限基线）。
     *
     * 用法：
     *   $roles = app(PermissionRegistry::class)->roles();
     *
     * 边界/注意：
     *   `grants_all` 为 true 的角色（owner），`permissions` 会返回**全部 key** ——
     *   调用方不用再自己判 grants_all，直接看 permissions 就是它的最终基线。
     *
     * @return array<int, array{key: string, name: string, desc: string, tone: string, tone_label: string, builtin: bool, locked: bool, grants_all: bool, permissions: array<int, string>}> 角色列表
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function roles(): array
    {
        return $this->data()['roles'];
    }

    /**
     * 某个角色的默认权限基线。
     *
     * 用法：
     *   $registry->permissionsOfRole('moderator');   // ['user.read', 'forum.post.pin', …]
     *   $registry->permissionsOfRole('不存在');      // []
     *
     * @param  string  $roleKey  角色标识
     * @return array<int, string> 该角色基线里的权限 key
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function permissionsOfRole(string $roleKey): array
    {
        return $this->data()['role_keys'][$roleKey] ?? [];
    }

    /**
     * 判断一个权限 key 是否存在（新增权限点时的合法性校验）。
     *
     * @param  string  $key  能力名
     * @return bool 存在返回 true
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function has(string $key): bool
    {
        return in_array($key, $this->allKeys(), true);
    }

    /**
     * 算出**某个人最终能做什么**：角色基线 + 个人增减。
     *
     * 用法：
     *   $registry->effectiveFor($user);   // ['user.read', 'forum.post.create', …]
     *
     * 边界/注意：
     *   1. 个人增减优先于角色基线：`granted = true` 加进来，`granted = false` 从基线里拿掉。
     *   2. 没有角色的用户（`role_id` 为空）只拿个人增减 —— 迁移期可能存在这种数据。
     *   3. 这里会**多查一次库**（个人增减表）。等鉴权中间件落地时再考虑按用户缓存；
     *      现在先保证正确。
     *
     * @param  User  $user  用户
     * @return array<int, string> 最终权限 key 列表（已去重）
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    鉴权中间件落地后，按 user_id 缓存这份结果
     */
    public function effectiveFor(User $user): array
    {
        $roleKey = $user->role?->key;
        $permissions = $roleKey === null ? [] : $this->permissionsOfRole($roleKey);

        $overrides = UserPermission::query()
            ->where('user_id', $user->getKey())
            ->with('permission:id,key')
            ->get();

        foreach ($overrides as $override) {
            $key = $override->permission?->key;
            if ($key === null) {
                continue;
            }

            if ($override->granted && ! in_array($key, $permissions, true)) {
                $permissions[] = $key;
            }

            if (! $override->granted) {
                $permissions = array_values(array_diff($permissions, [$key]));
            }
        }

        return array_values(array_unique($permissions));
    }

    /**
     * 清掉缓存与请求内记忆。**任何写操作之后都要调**。
     *
     * 用法：
     *   app(PermissionRegistry::class)->flush();
     *
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    /**
     * 按 user_id 缓存的"最终权限"。**一次请求只算一次。**
     *
     * @var array<int, array<int, string>>
     */
    private array $effective = [];

    /**
     * 判定入口：**某个主体有没有某个权限**。
     *
     * 用法：
     *   $registry->allows($admin, 'forum.post.delete');   // bool
     *   $registry->allows($user, 'article.create');
     *
     * 边界/注意：
     *   1. `$subject` 既可以是会员（`User`）也可以是管理员（`Admin`）——
     *      管理员会自动取其挂回的总表记录（`admins.user_id`）。
     *      这正是"权限只有一个主体"带来的好处：判定逻辑不用分支。
     *   2. **权限永远由服务端算**：这里读的是库里（经预加载层）的角色基线与个人增减，
     *      **跟请求里带了什么字段完全无关** —— 所以伪造 `role` / `permissions` 提不了权。
     *   3. 结果按 user_id 做**请求内**缓存（见 `$effective`）：一次请求里多个中间件
     *      不会把个人增减表查好几遍。写操作会 `flush()`，下一个请求自然生效。
     *   4. 拿不到总表记录（`Admin` 没挂、或人被删了）→ **一律 false**，不放行任何能力。
     *
     * @param  \App\Models\User|\App\Modules\Auth\Models\Admin  $subject  主体
     * @param  string  $ability  权限点 key，如 `forum.post.delete`
     * @return bool  有权限返回 true
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function allows(User|Admin $subject, string $ability): bool
    {
        $user = $subject instanceof Admin ? $subject->user : $subject;

        if ($user === null) {
            return false;
        }

        $permissions = $this->effective[$user->getKey()] ??= $this->effectiveFor($user);

        return in_array($ability, $permissions, true);
    }

    /**
     * 取权限点的**中文名**（403 提示要用，例如"你没有「删除帖子」权限"）。
     *
     * 用法：
     *   $registry->labelOf('forum.post.delete');   // '删除帖子'
     *   $registry->labelOf('不存在的.key');        // 原样返回 '不存在的.key'
     *
     * 边界/注意：
     *   找不到时**原样返回 key** 而不是报错 —— 提示里带个 key 也比"操作失败"有用，
     *   而且权限点被删掉时不会因为提示文案再把请求弄挂一次。
     *
     * @param  string  $ability  权限点 key
     * @return string  中文名（找不到则返回 key 本身）
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function labelOf(string $ability): string
    {
        foreach ($this->catalog() as $group) {
            foreach ($group['items'] as $item) {
                if ($item['key'] === $ability) {
                    return $item['label'];
                }
            }
        }

        return $ability;
    }

    /**
     * 取某个主体**自己那份**权限列表（登录 / `me` 接口下发给前端做按钮显隐）。
     *
     * 用法：
     *   $registry->permissionsOf($admin);   // ['user.read', 'article.create', …]
     *   $registry->permissionsOf($user);
     *
     * 边界/注意：
     *   1. 与 `allows()` 共用同一份请求内缓存，所以登录接口里算过一次，
     *      这次请求里后续的 `permission:` 中间件不会重算。
     *   2. **只算"他自己"的** —— 不接受"我要某人的权限"这种参数，
     *      从接口设计上就杜绝了越权查询。
     *   3. 前端拿到它**只用于显隐**，安全边界在 `permission:` 中间件（见模型文档 1.3）。
     *
     * @param  \App\Models\User|\App\Modules\Auth\Models\Admin  $subject  主体
     * @return array<int, string>  该主体的最终权限 key 列表
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function permissionsOf(User|Admin $subject): array
    {
        $user = $subject instanceof Admin ? $subject->user : $subject;

        if ($user === null) {
            return [];
        }

        return $this->effective[$user->getKey()] ??= $this->effectiveFor($user);
    }

    public function flush(): void
    {
        $this->data = null;
        $this->effective = [];
        Cache::forget(self::CACHE_KEY);
    }

    // ── 内部 ──────────────────────────────────────────────────────────────

    /**
     * 取数据（请求内记忆 → 跨请求缓存 → 查库）。
     *
     * @return array{catalog: array<int, mixed>, roles: array<int, mixed>, keys: array<int, string>, role_keys: array<string, array<int, string>>} 注册表
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    private function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && isset($cached['catalog'], $cached['roles'], $cached['keys'], $cached['role_keys'])) {
            return $this->data = $cached;
        }

        $built = $this->build();
        Cache::put(self::CACHE_KEY, $built, self::TTL);

        return $this->data = $built;
    }

    /**
     * 从数据库构建注册表。只在缓存未命中时执行。
     *
     * @return array{catalog: array<int, mixed>, roles: array<int, mixed>, keys: array<int, string>, role_keys: array<string, array<int, string>>} 注册表
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    private function build(): array
    {
        $permissions = Permission::query()
            ->orderBy('group_sort')
            ->orderBy('sort')
            ->orderBy('id')
            ->get(['id', 'key', 'label', 'description', 'group_title', 'is_builtin']);

        $catalog = [];

        foreach ($permissions as $permission) {
            $catalog[$permission->group_title] ??= ['title' => $permission->group_title, 'items' => []];
            $catalog[$permission->group_title]['items'][] = [
                'id' => $permission->id,
                'key' => $permission->key,
                'label' => $permission->label,
                'desc' => $permission->description,
                'custom' => ! $permission->is_builtin,
            ];
        }

        $keys = $permissions->pluck('key')->all();

        $roles = [];
        $roleKeys = [];

        // withCount('users')：后台的角色列表要显示「N 人」。
        // 这个数字在大改之前是**前端假数据** —— 现在由数据库算（一条子查询），
        // 而且它会随 roles 一起进缓存，不会每次请求都算一遍。
        foreach (Role::query()->orderBy('sort')->orderBy('id')->with('permissions:id,key')->withCount('users')->get() as $role) {
            // grants_all（owner）不存快照：这里现算成"全部权限"，
            // 新增权限点后它自动包含，不会有"快照过期"的问题
            $base = $role->grants_all
                ? $keys
                : $role->permissions->pluck('key')->all();

            $roleKeys[$role->key] = array_values($base);

            $roles[] = [
                'id' => $role->id,
                'key' => $role->key,
                'name' => $role->name,
                'desc' => $role->description,
                'tone' => $role->tone->value,
                'tone_label' => $role->tone->label(),
                'builtin' => $role->is_builtin,
                'locked' => $role->is_locked,
                'grants_all' => $role->grants_all,
                'users_count' => (int) $role->users_count,
                'permissions' => array_values($base),
            ];
        }

        return [
            'catalog' => array_values($catalog),
            'roles' => $roles,
            'keys' => $keys,
            'role_keys' => $roleKeys,
        ];
    }
}
