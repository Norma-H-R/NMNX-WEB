<?php

declare(strict_types=1);

/**
 * RbacService —— 权限点与角色的**写操作**规则
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块。
 * 用途：增删改权限点与角色。**每个写方法末尾都会 `flush()` 预加载缓存** ——
 *       忘了这一步，接口改了库但鉴权还读旧目录，那是最难查的一类 bug。
 * 谁在调：`PermissionController` / `RoleController`。
 *
 * 一条重要的设计收益：
 *   因为 `role_permissions` / `user_permissions` 存的是**权限点的数字 ID**，
 *   所以**改权限点的 key（改名）不需要同步任何引用** —— 前端那套
 *   `renamePermissionRefs` / `renamePermissionInRoles` 在我们这里根本不存在。
 *   这是"存 ID 不存 key"最直接的回报。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/08-rbac.md
 */

namespace App\Modules\Rbac\Services;

use App\Models\User;
use App\Modules\Rbac\Enums\RoleTone;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Support\ErrorCode;
use App\Modules\Support\Exceptions\ApiException;

final class RbacService
{
    /**
     * 权限点 key 的格式：**小写点分**，至少两段。
     *
     * 与 admin 前端 `PermissionManager.vue` 里的正则**逐字一致** ——
     * 后端拿它当中件里 `can()` 的能力名，随手起个带大写或空格的名字后面必然要改。
     */
    public const KEY_PATTERN = '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+$/';

    /**
     * 构造：注入预加载层（写完要 flush）。
     *
     * @param  PermissionRegistry  $registry  预加载层
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function __construct(private readonly PermissionRegistry $registry) {}

    // ── 权限点 ────────────────────────────────────────────────────────────

    /**
     * 新增一个权限点。
     *
     * 用法：
     *   $service->createPermission([
     *       'key' => 'media.alert', 'label' => '告警规则', 'description' => '…',
     *       'group_title' => '自媒体',
     *   ]);
     *
     * 边界/注意：
     *   1. key 必须匹配 `KEY_PATTERN`，且不能与已有重复 —— 重复会给调用方一个明确的错误。
     *   2. 新权限点一律 `is_builtin = false`（界面上新增的），
     *      这样重跑 `RbacSeeder` 不会把它冲掉。
     *   3. 分组必须**已存在**：不允许顺手造新分组。理由和前端一致 ——
     *      分组是"目录结构"，随手加会让后台多出一堆只有一个条目的组。
     *      真要新分组，往 `RbacSeeder` 里加，那是**一次性决策**，值得走代码。
     *   4. 排序取该组当前最大值 +1（追加到组尾）。
     *
     * @param  array{key: string, label: string, description?: string, group_title: string}  $data  权限点数据
     * @return Permission 新建的权限点
     *
     * @throws ApiException 标识格式不对 / 重复 / 分组不存在
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function createPermission(array $data): Permission
    {
        if (preg_match(self::KEY_PATTERN, $data['key']) !== 1) {
            throw new ApiException(ErrorCode::SYS_VALIDATION, '标识要是「模块.动作」的小写点分格式，比如 media.alert');
        }

        if (Permission::query()->where('key', $data['key'])->exists()) {
            throw new ApiException(ErrorCode::SYS_VALIDATION, '这个标识已经被占用了');
        }

        $groupSort = Permission::query()->where('group_title', $data['group_title'])->value('group_sort');

        if ($groupSort === null) {
            throw new ApiException(ErrorCode::SYS_VALIDATION, '分组不存在，请先选一个已有分组');
        }

        $sort = (int) Permission::query()->where('group_title', $data['group_title'])->max('sort');

        $permission = Permission::query()->create([
            'key' => $data['key'],
            'label' => $data['label'],
            'description' => $data['description'] ?? '',
            'group_title' => $data['group_title'],
            'group_sort' => $groupSort,
            'sort' => $sort + 1,
            'is_builtin' => false,
        ]);

        $this->registry->flush();

        return $permission;
    }

    /**
     * 修改一个权限点。
     *
     * 用法：
     *   $service->updatePermission($permission, ['key' => 'media.rule', 'label' => '告警规则']);
     *
     * 边界/注意：
     *   1. **改 key 不需要同步引用**（pivot 存的是 ID，见类注释）——
     *      这是本模块和前端 mock 最大的差别，别去抄前端那套同步逻辑。
     *   2. 不允许改分组（与前端一致）：分组一改，列表里位置突变，使用的人找不到。
     *
     * @param  Permission  $permission  目标权限点
     * @param  array{key?: string, label?: string, description?: string}  $data  要改的字段
     * @return Permission 修改后的权限点
     *
     * @throws ApiException 标识格式不对 / 与别人重复
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function updatePermission(Permission $permission, array $data): Permission
    {
        if (array_key_exists('key', $data) && $data['key'] !== $permission->key) {
            if (preg_match(self::KEY_PATTERN, $data['key']) !== 1) {
                throw new ApiException(ErrorCode::SYS_VALIDATION, '标识要是「模块.动作」的小写点分格式，比如 media.alert');
            }

            if (Permission::query()->where('key', $data['key'])->whereKeyNot($permission->getKey())->exists()) {
                throw new ApiException(ErrorCode::SYS_VALIDATION, '这个标识已经被占用了');
            }
        }

        $permission->fill(array_filter([
            'key' => $data['key'] ?? null,
            'label' => $data['label'] ?? null,
            'description' => $data['description'] ?? null,
        ], static fn ($value) => $value !== null))->save();

        $this->registry->flush();

        return $permission;
    }

    /**
     * 删除一个权限点。
     *
     * 用法：
     *   $service->deletePermission($permission);
     *
     * 边界/注意：
     *   引用清理靠**外键级联**（`role_permissions` / `user_permissions` 都是 cascadeOnDelete）
     *   —— 不会留下指向不存在权限的"幽灵项"。所以这里不需要手动清引用。
     *
     * @param  Permission  $permission  目标权限点
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function deletePermission(Permission $permission): void
    {
        $permission->delete();

        $this->registry->flush();
    }

    // ── 角色 ──────────────────────────────────────────────────────────────

    /**
     * 新增一个自定义角色。
     *
     * 用法：
     *   $service->createRole(['name' => '论坛运营', 'description' => '…', 'tone' => 'cyan']);
     *
     * 边界/注意：
     *   1. **key 由后端生成**（`custom-1`、`custom-2`…），调用方只起名字 ——
     *      跟前端一样：用户不该为"标识"这种东西操心，也没法保证唯一。
     *   2. 新角色不是内置、不可锁、不隐含全部权限，基线为空（由调用方随后 sync）。
     *
     * @param  array{name: string, description?: string, tone?: string}  $data  角色数据
     * @return Role 新建的角色
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function createRole(array $data): Role
    {
        $role = Role::query()->create([
            'key' => $this->nextCustomRoleKey(),
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            // tryFrom 而不是 from：没传 tone 时控制器会给一个空串，
            // 直接 from('') 会抛 ValueError（枚举不认识空串），这里退化成默认青色
            'tone' => RoleTone::tryFrom((string) ($data['tone'] ?? '')) ?? RoleTone::Cyan,
            'is_builtin' => false,
            'is_locked' => false,
            'grants_all' => false,
            'sort' => (int) Role::query()->max('sort') + 1,
        ]);

        $this->registry->flush();

        return $role;
    }

    /**
     * 修改一个角色（名字 / 说明 / 配色 / 权限基线）。
     *
     * 用法：
     *   $service->updateRole($role, ['permissions' => ['forum.read', 'forum.post.pin']]);
     *
     * 边界/注意：
     *   1. **`owner` 的基线不允许改** —— 它隐含全部权限，改它没有意义还容易误解。
     *   2. `permissions` 传的是**权限点的 key 列表**（前端就是这么传的），
     *      在这里翻译成 ID 落库；有任何一个 key 不存在就整体拒绝，
     *      不做"能识别几个算几个" —— 那种静默丢权限的行为线上极难发现。
     *   3. 传了 `permissions` 就是**全量替换**（sync），不是追加。
     *
     * @param  Role  $role  目标角色
     * @param  array{name?: string, description?: string, tone?: string, permissions?: array<int, string>}  $data  要改的字段
     * @return Role 修改后的角色
     *
     * @throws ApiException 基线里有不存在的权限点 / 想改 owner 的基线
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function updateRole(Role $role, array $data): Role
    {
        if (array_key_exists('permissions', $data)) {
            if ($role->grants_all) {
                throw new ApiException(ErrorCode::SYS_VALIDATION, '超级管理员隐含全部权限，不需要也不能单独配置基线');
            }

            $unknown = array_values(array_diff($data['permissions'], $this->registry->allKeys()));

            if ($unknown !== []) {
                throw new ApiException(ErrorCode::SYS_VALIDATION, '这些权限点不存在：'.implode('、', $unknown));
            }

            $ids = Permission::query()->whereIn('key', $data['permissions'])->pluck('id')->all();
            $role->permissions()->sync($ids);
        }

        // tryFrom：空串（没传）退化成 null，随后被 array_filter 剔掉 —— 表示"不动配色"
        $tone = RoleTone::tryFrom((string) ($data['tone'] ?? ''));

        $role->fill(array_filter([
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'tone' => $tone,
        ], static fn ($value) => $value !== null))->save();

        $this->registry->flush();

        return $role;
    }

    /**
     * 删除一个角色。
     *
     * 用法：
     *   $service->deleteRole($role);
     *
     * 边界/注意：
     *   1. `is_locked` 的角色（owner / member）**不允许删** ——
     *      删了 owner 没人能管权限，删了 member 新用户没身份可挂。
     *   2. 把挂在这个角色下的用户**转成 member**，再删角色 ——
     *      顺序不能反：用户总表的外键是 `restrictOnDelete`，还有人用时数据库会拒绝删。
     *
     * @param  Role  $role  目标角色
     * @return int 被转移到 member 的用户数
     *
     * @throws ApiException 角色是系统依赖
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function deleteRole(Role $role): int
    {
        if ($role->is_locked) {
            throw new ApiException(ErrorCode::SYS_VALIDATION, '「'.$role->name.'」是系统依赖，不能删除');
        }

        $fallbackId = Role::query()->where('key', 'member')->value('id');

        $moved = User::query()->where('role_id', $role->getKey())->update(['role_id' => $fallbackId]);

        $role->delete();

        $this->registry->flush();

        return $moved;
    }

    /**
     * 生成下一个自定义角色的 key（`custom-1`、`custom-2`…）。
     *
     * 边界/注意：
     *   取"第一个没被占用的编号"，而不是"最大编号 +1" —— 删掉 custom-1 之后再建，
     *   编号可以复用，不会一路涨上去。
     *
     * @return string 可用的角色标识
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    private function nextCustomRoleKey(): string
    {
        $index = 1;

        while (Role::query()->where('key', 'custom-'.$index)->exists()) {
            $index++;
        }

        return 'custom-'.$index;
    }
}
