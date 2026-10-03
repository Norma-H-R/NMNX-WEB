<?php

declare(strict_types=1);

/**
 * UserPermission —— 个人权限增减（角色基线之外的例外）
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块。
 * 用途：一个人身上"多给的"和"收回的"权限点。`granted` 区分这两种：
 *         granted = true   在角色基线之外**额外**授予
 *         granted = false  从角色基线里**收回**（"这个人是管理员，但就是不能删用户"）
 * 谁在调：RbacService（算某人最终能做什么）、用户详情抽屉里的逐项配置。
 *
 * 为什么要有"收回"而不是只能加：
 *   前端类型注释写的就是"角色基线之外额外调整过的权限点（**含被去掉的**）"。
 *   只支持"加"的话，想摘掉一个人某个权限，就得给他新建一个角色 —— 角色会爆炸。
 *
 * 主体为什么叫 `user_id` 而不是多态的 `model_id`：
 *   因为我们有**用户总表**（`users` 是所有"人"，管理员是它 + `admins` 分表）。
 *   所以"谁能有权限"这件事只需要指向 `users`，用不上多态。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/08-rbac.md
 */

namespace App\Modules\Rbac\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserPermission extends Model
{
    /**
     * 可批量赋值字段。
     *
     * @var list<string>
     */
    protected $fillable = ['user_id', 'permission_id', 'granted'];

    /**
     * 取字段的类型转换表。
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
            'granted' => 'boolean',
        ];
    }

    /**
     * 这条例外属于谁。
     *
     * @return BelongsTo<User, $this> 用户
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 这条例外针对哪个权限点。
     *
     * @return BelongsTo<Permission, $this> 权限点
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
