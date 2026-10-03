<?php

declare(strict_types=1);

/**
 * Role —— 角色 / 身份
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块。
 * 用途：一个角色同时是两件事 ——
 *         1) 给人看的标签（`name` + `tone` 配色）
 *         2) 一份**默认权限基线**（这个身份的人一进来默认能做什么）
 *       具体某个人在基线上还能单独加减（见 `UserPermission`）。
 * 谁在调：RbacService、角色管理接口、注册时给新用户挂 member。
 *
 * 三个布尔字段的区别（容易混，别合并）：
 *   is_builtin   种子导入的（相对"界面上自定义的"）—— 只影响界面打不打"自定义"标
 *   is_locked    **不允许删除**（owner / member 是系统依赖）
 *   grants_all   **隐含全部权限**（owner）—— 为 true 时不存权限快照，
 *                否则后续新增权限点，owner 的快照就过期了（前端特意这么设计的，照抄）
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/08-rbac.md
 */

namespace App\Modules\Rbac\Models;

use App\Models\User;
use App\Modules\Rbac\Enums\RoleTone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Role extends Model
{
    /**
     * 可批量赋值字段。
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'name',
        'description',
        'tone',
        'is_builtin',
        'is_locked',
        'grants_all',
        'sort',
    ];

    /**
     * 取字段的类型转换表。
     *
     * 用法：
     *   $role->grants_all;   // bool
     *   $role->tone;         // RoleTone 枚举
     *
     * @return array<string, string> 字段 => cast 规则
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    protected function casts(): array
    {
        return [
            'tone' => RoleTone::class,
            'is_builtin' => 'boolean',
            'is_locked' => 'boolean',
            'grants_all' => 'boolean',
        ];
    }

    /**
     * 这个角色的默认权限基线。
     *
     * 用法：
     *   $role->permissions()->pluck('key');   // ['user.read', …]
     *
     * 边界/注意：
     *   `grants_all` 的角色**不查这里**（它隐含全部）。别在别处直接用它做鉴权，
     *   统一走 `RbacService::permissionsOfRole()`，那里处理了 grants_all。
     *
     * @return BelongsToMany<Permission, $this> 权限点集合
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /**
     * 挂着这个角色的用户。
     *
     * 用法：
     *   $role->users()->count();   // 删角色前的"影响范围"
     *
     * 边界/注意：
     *   用户总表外键是 `restrictOnDelete`，所以还有人在用时**删不掉** ——
     *   删角色前要先把这些人转成 member（见 RbacService::deleteRole()）。
     *
     * @return HasMany<User, $this> 用户集合
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
