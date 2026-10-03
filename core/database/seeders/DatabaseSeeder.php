<?php

declare(strict_types=1);

/**
 * DatabaseSeeder —— 全库种子的总入口
 *
 * 本文件属于 core（纯 API 后端）的全局种子入口。
 * 用途：按**顺序**调用各模块自己的 Seeder。放在这里的东西只做"编排"，
 *       真正的造数逻辑写在各模块的 `<Name>Seeder` 里。
 * 谁在调：`php artisan db:seed`。
 *
 * 当前会造出两个开发/测试账号（密码都是 `11111`，本地统一一个密码）：
 *   root        后台管理员（AdminSeeder）
 *   11111       前台会员，手机号即账号（MemberSeeder）
 *
 * @version 0.2.0
 *
 * @since   2026-10-03
 * @see     docs/modules/01-auth.md
 * @see     docs/modules/07-member.md
 */

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * 执行全部种子。
     *
     * 用法：
     *   php artisan db:seed
     *   php artisan db:seed --class=AdminSeeder     # 只造某一个
     *
     * 边界/注意：
     *   两个 Seeder 的幂等策略**不同**（这是刻意的）：
     *   · `AdminSeeder` 保守 —— 账号已存在就跳过，不重置密码（防"随手 seed 把线上管理员踢下线"）。
     *   · `MemberSeeder` 会重置 —— 它是纯测试夹具（手机号 11111），
     *     "每次都是同一个可登录状态"比保护它更重要。
     *
     *
     * @version 0.2.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function run(): void
    {
        $this->call([
            // 权限目录与内置角色。**必须排在最前** —— 下面两个都要挂角色：
            //   后台管理员挂 owner、新会员挂 member
            RbacSeeder::class,

            // 后台管理员。没有它 admins 表是空的，后台登录无从谈起。
            // 它还会把管理员**挂回用户总表**（权限的唯一主体，见 permissions-model.md 第 6.1 节）。
            AdminSeeder::class,

            // 前台测试会员（手机号 11111 / 密码 11111），前后端联调直接用。
            MemberSeeder::class,
        ]);
    }
}
