<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立 admins 表 —— 后台管理者
 *
 * 本文件属于 core（纯 API 后端）的 Auth 模块。
 * 用途：**后台管理者（我们运营方的人）独立的账号表**，与前台会员的 `users` 表分开。
 *       这是需求 D1 的落地：两套人、两个登录入口、两个守卫。
 *       混用一张表会让"会员令牌能访问 admin 接口"变成可能。
 * 谁在调：`php artisan migrate`。
 *
 * 为什么登录标识是 `username` 而不是 `email`：
 *   `admin/` 前端的登录页已经是"管理员账号"输入框（见 `admin/src/views/LoginView.vue`
 *   的 `loginForm.username`），后端必须对齐它。`email` 保留但可空，只用于通知。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/01-auth.md
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
     *   `status` 的默认值这里**硬编码 'active'** 而不是引用 `AdminStatus` 枚举 ——
     *   迁移是历史快照，将来枚举改名/改值不应该改变这条已经跑过的迁移的含义。
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
        Schema::create('admins', function (Blueprint $table): void {
            $table->id()->comment('自增主键。管理员数量少，内部用；对外展示用 username / name');

            $table->string('username', 64)->unique()->comment('登录账号，前端登录框填的就是它');
            $table->string('name', 64)->comment('显示名，用于界面与审计日志');
            $table->string('email', 190)->nullable()->unique()->comment('邮箱，可空；仅用于通知');
            $table->string('password')->comment('密码哈希，由 casts 的 hashed 自动处理');

            $table->string('status', 16)->default('active')->comment('active（正常）| disabled（禁用，不能登录），见 AdminStatus');

            $table->string('remember_token', 100)->nullable()->comment('"记住我"令牌。⚠️ API 场景恒为空：走 Bearer 令牌');
            $table->timestamp('last_login_at')->nullable()->comment('最后一次登录成功时间');
            $table->string('last_login_ip', 45)->nullable()->comment('最后登录 IP。45 位以兼容 IPv6');

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');
        });
    }

    /**
     * 回滚迁移。
     *
     * 用法：
     *   php artisan migrate:rollback
     *
     * 边界/注意：
     *   会**直接删表连数据一起丢**。生产环境回滚前先备份。
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
        Schema::dropIfExists('admins');
    }
};
