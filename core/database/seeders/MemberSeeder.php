<?php

declare(strict_types=1);

/**
 * MemberSeeder —— 测试用的会员账号
 *
 * 本文件属于 core（纯 API 后端）的 Member 模块。
 * 用途：给前后端联调一个**开箱即用**的会员，免得每次都要先注册。
 * 谁在调：`php artisan db:seed` / `--class=MemberSeeder`，以及 `DatabaseSeeder`。
 *
 * 测试凭据（与 AdminSeeder 的开发密码同为 `11111`，全站一个密码好记）：
 *   手机号 `11111`  密码 `11111`
 *
 * ⚠️ 与 `AdminSeeder` 的策略**不同**：这个 Seeder 会**重置**这个测试账号的密码与资料。
 *   因为它是纯测试夹具（`phone = 11111` 这种号不可能有真实用户），
 *   "每次跑完都是同一个可登录状态"比"保护它"更重要。管理员那边则是保守的。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/07-member.md
 */

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Member\Enums\MemberStatus;
use App\Modules\Rbac\Models\Role;
use Illuminate\Database\Seeder;

final class MemberSeeder extends Seeder
{
    /** 测试用手机号（登录标识；一期**不做数字校验**，见 MemberLoginRequest） */
    private const PHONE = '11111';

    /** 测试用密码 —— 与手机号一致，方便手敲 */
    private const PASSWORD = '11111';

    /** 测试用邮箱 */
    private const EMAIL = 'test@example.com';

    /** 新会员挂的角色（Rbac 的默认身份） */
    private const ROLE = 'member';

    /**
     * 执行种子。
     *
     * 用法：
     *   php artisan db:seed --class=MemberSeeder
     *
     * 边界/注意：
     *   1. 用「手机号或邮箱命中同一个已存在行」的方式找目标，而不是 `updateOrCreate(['phone' => …])`——
     *      因为库里可能已有一行"只有 email、没有 phone"的老夹具（Laravel 默认工厂造的），
     *      直接按 phone 新建会撞 `users.email` 的唯一约束。
     *   2. `password` 赋明文，由模型的 `hashed` cast 哈希，**不要**再 `Hash::make`。
     *
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function run(): void
    {
        $user = User::query()
            ->where('phone', self::PHONE)
            ->orWhere('email', self::EMAIL)
            ->first();

        if ($user === null) {
            $user = new User;
        }

        $user->name = '南门会员';
        $user->email = self::EMAIL;
        $user->phone = self::PHONE;
        $user->password = self::PASSWORD;
        $user->status = MemberStatus::Active;
        // 角色挂在**用户总表**上（Rbac）。所以 RbacSeeder 必须先跑 —— DatabaseSeeder 里已排序。
        $user->role_id = Role::query()->where('key', self::ROLE)->value('id');
        $user->tier = 'silver';
        $user->points = 2860;
        $user->save();

        $this->command?->info('测试会员就绪：手机号 ['.self::PHONE.']，密码 ['.self::PASSWORD.']（tier=silver，points=2860）。');
    }
}
