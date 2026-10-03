<?php

declare(strict_types=1);

/**
 * User —— 前台会员模型（也就是"用户总表"）
 *
 * 本文件属于 core（纯 API 后端）的 Member 模块，但留在框架默认的 `App\Models` 位置。
 * 原因：`users` 是**总表**（所有"人"都在这一张），会员目前没有专属字段需要再开子表，
 *       所以直接用 Laravel 自带的 User 模型 + 表，不再新建一个 Member 模型去指同一张表。
 *
 * 用途：`/api/v1/member/*` 这一层认证的主体就是它（`auth:member`，provider = users）。
 *       同时它也是 Rbac 的授权主体 —— 角色挂在**总表**上，所以管理员和会员共用一套角色体系。
 * 谁在调：会员登录/注册服务、`auth:member` 守卫、后台的用户管理、RbacService。
 *
 * 与 `App\Modules\Auth\Models\Admin` 是**两张表、两个模型**（需求 D1）：
 *   管理员 = `admins` 表；会员 = `users` 表。两者不混用。
 *   将来管理员会通过 `admins.user_id` 挂回这张总表（见 docs/modules/07-member.md）。
 *
 * 本模型**只做数据映射**：登录规则、状态判断（`canLogin()`）、权限计算都在各自的模块里。
 *
 * @version 0.4.0
 *
 * @since   2026-10-03
 * @see     docs/modules/07-member.md
 * @see     docs/modules/08-rbac.md
 */

namespace App\Models;

use App\Modules\Member\Enums\MemberStatus;
use App\Modules\Rbac\Models\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password', 'status', 'tier', 'points', 'role_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** 公开 ID 的前缀。对外展示形如 `NMX-U-000017`，**不要给用户看自增 id**。 */
    public const PUBLIC_ID_PREFIX = 'NMX-U-';

    /**
     * 注册模型事件：新用户落库后自动分配公开 ID。
     *
     * 用法：
     *   // 无需手动调用 —— User::factory()->create() / 注册接口都会自动触发
     *   $user->public_id;   // 'NMX-U-000017'
     *
     * 边界/注意：
     *   1. 公开 ID 由**自增 id 派生**（零填充到 6 位），所以必须等 INSERT 之后才能算 ——
     *      这就是为什么用 `created` 事件而不是 `creating`（那时还没有 id）。
     *      多一次 UPDATE，换来"打印出来跟 id 一样好读"，值。
     *   2. SQLite/MySQL 的自增 id **不会被复用**（建表用的是 autoincrement），
     *      所以删号后新号不会撞到旧 public_id。若哪天换成会复用的方案，
     *      这里要改成独立的序列/随机码。
     *   3. 用 `saveQuietly()`：只补一个字段，不该再触发一轮 updated 事件。
     *   4. 已有数据的回填见迁移注释里的 @todo。
     *
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    存量用户的 public_id 回填命令
     */
    protected static function booted(): void
    {
        static::created(static function (self $user): void {
            if ($user->public_id !== null) {
                return;
            }

            $user->forceFill([
                'public_id' => self::PUBLIC_ID_PREFIX.str_pad((string) $user->getKey(), 6, '0', STR_PAD_LEFT),
            ])->saveQuietly();
        });
    }

    /**
     * 这个人的角色（单角色）。
     *
     * 用法：
     *   $user->role->key;              // 'member'
     *   $user->role->grants_all;       // owner 为 true
     *
     * 边界/注意：
     *   契约里 `role` 是**单数** —— 一个人一个角色，个人差异用 `user_permissions` 增减，
     *   不是"挂多个角色"。所以这里返回 BelongsTo 而不是 BelongsToMany。
     *
     * @return BelongsTo<Role, $this> 角色
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * 取字段的类型转换表。
     *
     * 用法：
     *   $user->status;            // MemberStatus 枚举实例（active / muted / banned）
     *   $user->status->value;     // 'active' —— 出参用这个
     *   $user->password = '明文';  // hashed cast 自动哈希，不要手动 Hash::make
     *   $user->last_login_at;     // Carbon 或 null
     *   $user->last_seen_at;      // Carbon 或 null（≠ 最后登录时间）
     *
     * 边界/注意：
     *   1. `'password' => 'hashed'` 让赋值自动哈希；**再手动 Hash::make 会二次哈希**。
     *   2. `status` 转成枚举后，写 SQL 条件要用 `MemberStatus::Active`，
     *      不要写字符串 `'active'`（枚举实现了 BackedEnum，能进查询构造器）。
     *
     * @return array<string, string> 字段 => cast 规则
     *
     * @version 0.4.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => MemberStatus::class,
            'points' => 'integer',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
