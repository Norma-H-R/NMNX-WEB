<?php

declare(strict_types=1);

/**
 * AdminSeeder —— 初始化后台管理员（并把管理员挂回用户总表）
 *
 * 本文件属于 core（纯 API 后端）的 Auth 模块。
 * 用途：建一个能登录后台的管理员。开发期默认 `root` / `11111`。
 * 谁在调：`php artisan db:seed`（经 DatabaseSeeder）、`--class=AdminSeeder`。
 *
 * 为什么还要"挂回总表"：
 *   **权限的唯一主体是 `users` 总表**（角色 `role_id` 与个人增减都挂在那儿）。
 *   管理员是 `admins` 分表里的人，必须通过 `admins.user_id` 指向一条总表记录，
 *   才能"有角色、有权限"。不挂的话，`can()` 一上线，管理员就是**没有任何权限的人**。
 *
 * ⚠️ 顺序要求：**必须在 RbacSeeder 之后跑**（要先有 `owner` 角色）。
 *    DatabaseSeeder 里的调用顺序已保证这一点。
 *
 * @version 0.2.0
 * @since   2026-10-03
 * @see     docs/permissions-model.md 第 6.1 节
 */

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Auth\Enums\AdminStatus;
use App\Modules\Auth\Models\Admin;
use App\Modules\Member\Enums\MemberStatus;
use App\Modules\Rbac\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class AdminSeeder extends Seeder
{
    /** 开发期默认密码（可用 .env 的 ADMIN_SEED_PASSWORD 覆盖） */
    private const DEFAULT_PASSWORD = '11111';

    /** 超级管理员角色（隐含全部权限，不存快照） */
    private const OWNER_ROLE = 'owner';

    /**
     * 执行种子。
     *
     * 用法：
     *   php artisan db:seed --class=AdminSeeder
     *
     *   # 想自定义账号与密码就在 .env 里加：
     *   ADMIN_SEED_USERNAME=root
     *   ADMIN_SEED_PASSWORD=你的密码
     *
     * 边界/注意：
     *   1. 账号已存在时**不重置密码、不改状态**，但**仍会执行"挂回总表"**
     *      （原来是整体跳过，那样已存在的 root 永远挂不上 —— 这个坑改掉了）。
     *   2. `password` 直接赋明文，由 `Admin` 模型的 `hashed` cast 负责哈希；
     *      **不要在这里 `Hash::make()`**（会二次哈希，密码永远登录不上）。
     *   3. 用的是默认密码时会打印出来并附警告 —— 开发期需要知道用什么登录。
     *
     * @return void
     *
     * @version 0.2.0
     * @since   2026-10-03
     * @todo    无
     */
    public function run(): void
    {
        $username = (string) env('ADMIN_SEED_USERNAME', 'root');
        $password = (string) env('ADMIN_SEED_PASSWORD', '');

        $admin = Admin::query()->where('username', $username)->first();

        if ($admin === null) {
            $usingDefaultPassword = $password === '';

            $admin = new Admin;
            $admin->username = $username;
            $admin->name = '超级管理员';
            $admin->password = $usingDefaultPassword ? self::DEFAULT_PASSWORD : $password;
            $admin->status = AdminStatus::Active;
            $admin->save();

            $this->command?->info("已创建管理员 [{$username}]，status=active。");

            if ($usingDefaultPassword) {
                $this->command?->warn(
                    '使用的是默认密码 ['.self::DEFAULT_PASSWORD.']，上线前必须改掉，或在 .env 里配置 ADMIN_SEED_PASSWORD。',
                );
            }
        } else {
            $this->command?->warn("管理员 [{$username}] 已存在，跳过密码与状态（不会重置）。");
        }

        $this->linkToUserTable($admin);
    }

    /**
     * 把管理员挂回用户总表，并保证它挂着 owner 角色。
     *
     * 边界/注意：
     *   1. **幂等**：已经挂过就只校正角色（被别人误改能自愈），不会重复建总表记录。
     *   2. 总表那条记录的密码是**随机串且不落任何地方** ——
     *      它**不是给人登录用的**（管理员的入口是 `admins` 表 + `auth:admin`）。
     *      留一个已知密码会让这条记录变成一个能登进会员端的后门。
     *   3. 邮箱用 `admin-<username>@nmnx.local`：`users.email` 是唯一且非空，
     *      必须给一个；`.local` 保证收不到邮件，也不会和真实会员撞。
     *
     * @param  \App\Modules\Auth\Models\Admin  $admin  目标管理员
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function linkToUserTable(Admin $admin): void
    {
        $roleId = Role::query()->where('key', self::OWNER_ROLE)->value('id');

        if ($roleId === null) {
            $this->command?->error(
                '找不到 ['.self::OWNER_ROLE.'] 角色 —— 请先跑 RbacSeeder（DatabaseSeeder 已保证顺序）。',
            );

            return;
        }

        $user = $admin->user;

        if ($user === null) {
            $user = new User;
            $user->name = $admin->name;
            $user->email = 'admin-'.$admin->username.'@nmnx.local';
            $user->password = Str::random(40);
            $user->status = MemberStatus::Active;
            $user->role_id = $roleId;
            $user->save();
        } elseif ($user->role_id !== $roleId) {
            $user->role_id = $roleId;
            $user->save();
        }

        if ($admin->user_id !== $user->getKey()) {
            $admin->user_id = $user->getKey();
            $admin->save();
        }

        $this->command?->info(
            "管理员 [{$admin->username}] 已挂回总表：public_id={$user->public_id}，角色=".self::OWNER_ROLE.'。',
        );
    }
}
