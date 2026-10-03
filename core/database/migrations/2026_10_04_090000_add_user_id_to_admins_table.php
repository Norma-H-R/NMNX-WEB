<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 给 admins 加 user_id —— 把管理员挂回**用户总表**
 *
 * 本文件属于 core（纯 API 后端）的 Rbac / Auth 模块。
 * 用途：**权限只有一个主体** —— 角色（`role_id`）与个人增减（`user_permissions`）
 *       都挂在 `users` 总表上。管理员要能"有权限"，就必须有一条总表记录。
 *       这一列就是那个关联（文档里早就写了"将来管理员会通过 admins.user_id 挂回这张总表"）。
 *
 * 不做这步会怎样：`can()` 一上线，**管理员在权限体系里是"没有角色的人"**，
 * 于是整站 403 —— 后台自己把自己锁死。
 *
 * 为什么用 `nullOnDelete` 而不是 cascade / restrict：
 *   - `cascade`：删掉总表用户会**连带删掉管理员账号**，太狠（而且"删除用户"那个权限
 *     本来是给会员用的，不该误伤管理员）；
 *   - `restrict`：会让"删除用户"直接撞数据库报错，错误信息对使用者毫无意义；
 *   - `nullOnDelete`：账号还在、只是**权限归零**（什么也做不了，但能被人发现并修）。
 *     权限归零是"安静的失败"，但比误删账号安全 —— 这条是有取舍的，已知并接受。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/permissions-model.md 第 6.1 节
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
     *   允许为空：**存量管理员**还没有总表记录，要由 `AdminSeeder` 建好并挂上。
     *   不为空只是"代码层保证"，数据库这里不强制（迁移时还没有记录可挂）。
     *
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            // ⚠️ comment() 必须在 constrained() 之前 —— 写在后面会挂到外键上，列上就没备注了
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->comment('对应的总表用户（users）。角色的权限都挂在总表上，管理员通过它取得权限')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * 回滚迁移。
     *
     * 用法：
     *   php artisan migrate:rollback
     *
     * 边界/注意：
     *   要先删外键再删列（MySQL 不允许删掉还被外键引用的列）。
     *
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
