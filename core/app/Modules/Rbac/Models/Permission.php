<?php

declare(strict_types=1);

/**
 * Permission —— 权限点（能力名）
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块。
 * 用途：一条记录 = 一个能力，`key` 就是后端中间件里 `can('forum.post.delete')` 用的那串。
 * 谁在调：RbacService（角色的默认权限）、权限目录接口、鉴权中间件。
 *
 * 与 `Role` 的关系：多对多（`role_permissions`）。**用数字 ID 做外键而不是存 key** ——
 * 权限点允许改名，存 ID 的话改名天然安全（详见建表迁移里的说明）。
 *
 * 本模型**只做数据映射**：能不能删、改名要不要同步引用，都在 RbacService 里。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/08-rbac.md
 */

namespace App\Modules\Rbac\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Permission extends Model
{
    /**
     * 可批量赋值字段。
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'label',
        'description',
        'group_title',
        'group_sort',
        'sort',
        'is_builtin',
    ];

    /**
     * 取字段的类型转换表。
     *
     * 用法：
     *   $permission->is_builtin;   // bool
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
            'is_builtin' => 'boolean',
        ];
    }

    /**
     * 拥有这个权限点的角色（默认权限基线）。
     *
     * 用法：
     *   $permission->roles()->count();   // 有几个角色的基线里含它
     *
     * 边界/注意：
     *   这是**角色基线**的引用数；个人增减的引用在 `UserPermission` 上，
     *   删权限点时要两处都清（见 RbacService::deletePermission()）。
     *
     * @return BelongsToMany<Role, $this> 角色集合
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }

    /**
     * 个人增减记录（谁额外给了 / 收回了这个权限点）。
     *
     * @return HasMany<UserPermission, $this> 个人增减记录
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function userOverrides(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }
}
