<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 给 users（用户总表）补会员字段
 *
 * 本文件属于 core（纯 API 后端）的 Member 模块。
 * 用途：把 `users` 从"Laravel 默认骨架"升级成**用户总表** —— 所有"人"都在这张表里
 *       （会员是总表里的一行；管理员将来通过 `admins.user_id` 挂到总表，见 07-member.md）。
 * 谁在调：`php artisan migrate`。
 *
 * 三个字段的意义：
 *   phone          预留给「短信登录」（需求 C-6 的三类登录方式之一），一期注册不填
 *   status         会员状态（active / disabled），对应 ErrorCode 里的账号禁用分支
 *   last_login_*   登录行为审计，与管理端的 admins 表字段对齐
 *
 * 注意：`->after()` / `->comment()` 在 SQLite 上会被忽略（无害），
 * 但对后期迁 MySQL 有意义 —— 这正是「用标准 Schema API、不写方言」的活例子。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/07-member.md
 */
return new class extends Migration
{
    /**
     * 执行迁移。
     *
     * 用法：
     *   php artisan migrate
     *
     * 边界/注意：
     *   phone 用 `nullable()->unique()`：SQLite/MySQL 的唯一索引都允许**多个 NULL**，
     *   所以"没填手机号"的会员不会互相撞唯一约束。
     *
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable()->unique()->after('email')->comment('手机号，预留给短信登录');
            $table->string('status', 16)->default('active')->after('phone')->comment('active | disabled，见 MemberStatus');
            $table->timestamp('last_login_at')->nullable()->after('remember_token')->comment('最后登录成功时间');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at')->comment('最后登录 IP（45 位兼容 IPv6）');
        });
    }

    /**
     * 回滚迁移。
     *
     * 用法：
     *   php artisan migrate:rollback
     *
     * 边界/注意：
     *   `phone` 上有唯一索引，回滚要**先删索引再删列**（MySQL 不允许删掉还被索引引用的列）。
     *
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['phone']);
            $table->dropColumn(['phone', 'status', 'last_login_at', 'last_login_ip']);
        });
    }
};
