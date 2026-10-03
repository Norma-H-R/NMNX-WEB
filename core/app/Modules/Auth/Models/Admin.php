<?php

declare(strict_types=1);

/**
 * Admin —— 后台管理者模型
 *
 * 本文件属于 core（纯 API 后端）的 Auth 模块。
 * 用途：`admins` 表的 Eloquent 映射 + 令牌能力（`HasApiTokens`）。
 *       `/api/v1/admin/*` 整个守卫层认证的就是它。
 * 谁在调：`AdminAuthService`（登录）、`auth:admin` 守卫、后台的账号管理。
 *
 * ⚠️ 与 `App\Models\User`（前台会员）是**两张表、两个模型**，永不混用：
 *   需求 D1。混用会让会员令牌有穿过 admin 守卫的可能（见 docs/README.md 的守卫表）。
 *
 * 本模型**只做数据映射**：不写登录规则、不写状态判断（`canLogin()` 在枚举上）。
 *
 * ⚠️ `#[UseFactory]` 这一行不能省（已实测）：
 *   Laravel 按 `App\` 之后的路径反推工厂类名，模型在 `App\Modules\Auth\Models\` 会被
 *   推成 `Database\Factories\Modules\Auth\Models\AdminFactory`（那个类不存在），
 *   `Admin::factory()` 直接抛 "Class not found"。**模块化的模型都必须显式声明工厂。**
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/01-auth.md
 */

namespace App\Modules\Auth\Models;

use App\Models\User;
use App\Modules\Auth\Enums\AdminStatus;
use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['username', 'name', 'email', 'password', 'status'])]
#[Hidden(['password', 'remember_token'])]
#[UseFactory(AdminFactory::class)]
class Admin extends Authenticatable
{
    /**
     * 说明：`HasApiTokens` 提供 `createToken()` / `currentAccessToken()`，
     * 是登录签发与登出吊销令牌的来源。**不要自己拼 `personal_access_tokens` 表**。
     */
    use HasApiTokens, HasFactory;

    /**
     * 关联的表名。
     *
     * 用法：
     *   Admin::query()->where('status', AdminStatus::Active)->count();
     *
     * 边界/注意：
     *   类名 `Admin` 复数推断出来就是 `admins`，本来可以省略这一行。
     *   这里**显式写出来**是为了将来有人把类改名或挪命名空间时，表和数据的对应关系不会静默变化。
     *
     * @var string
     */
    protected $table = 'admins';

    /**
     * 取字段的类型转换表。
     *
     * 用法：
     *   $admin->status;           // AdminStatus 枚举实例，不是字符串
     *   $admin->status->value;    // 'active' —— 出参用这个
     *   $admin->password = '明文'; // 赋值时自动 bcrypt 哈希，不要手动 Hash::make
     *
     * 边界/注意：
     *   1. `'password' => 'hashed'` 让赋值自动哈希；**再手动 `Hash::make()` 会二次哈希**，
     *      结果是密码永远校验不过。这是本项目最容易踩的一个坑。
     *   2. `status` 被转成枚举后，写 SQL 条件时要用 `AdminStatus::Active`，
     *      不要写字符串 `'active'` —— 枚举能进查询构造器，因为它实现了 `BackedEnum`。
     *
     * @return array<string, string>
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
            'password' => 'hashed',
            'status' => AdminStatus::class,
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * 对应的**用户总表**记录。
     *
     * 用法：
     *   $admin->user?->role->key;     // 'owner'
     *
     * 边界/注意：
     *   1. **权限的唯一主体是 `users` 总表** —— 管理员靠这条关联才有角色、才有权限
     *      （`admins.user_id` 由 `AdminSeeder` 挂上）。
     *   2. 可以为 null（存量数据 / 被人手工清掉）—— 那种情况下管理员**没有任何权限**，
     *      调用方要能接住 null，别直接 `->role` 链下去。
     *
     * @return BelongsTo<User, $this>  总表用户
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
