<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 让 users（用户总表）挂上角色，并补契约要求的两个字段
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块。
 * 用途：admin 前端的契约草稿（`admin/src/types/user.ts` 的 UserRecord）要求用户身上有
 *       `role`、`publicId`、`lastSeenAt`，这张迁移把缺的补上。
 *
 * 三个字段：
 *   role_id       角色（**单角色**，不是多对多 —— 契约里 `role: UserRole` 是单数）
 *   public_id     对外公开 ID，形如 `NMX-U-000017`。契约明确要求"后端分配、前端只展示"
 *   last_seen_at  最后活跃时间。**它和 last_login_at 不是一回事**：登录是"进门"，
 *                 活跃是"还在屋里"。审计模块要做"页面停留时长"就得靠它。
 *
 * 为什么 `role_id` 用 `restrictOnDelete` 而不是 `nullOnDelete`：
 *   前端删角色的约定是"把挂在它下面的用户转成 member"。用 restrict 的话，
 *   **数据库会拒绝**删掉一个还有人在用的角色 —— 逼着调用方先把人转走，
 *   而不是留一批 role_id 为空的用户（那种用户没有任何权限，排查起来很痛苦）。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/08-rbac.md
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
     *   1. `role_id` 允许为空 —— 已有用户还没有角色，而且 Rbac 种子要在这之后才跑。
     *      代码里注册新用户时会显式挂上 member 角色，不为空只是"数据库层的兜底"。
     *   2. `public_id` 唯一但可空：老数据补不出公开 ID（要靠 `users:backfill-public-id`
     *      之类的命令补），新用户由模型的 `created` 钩子自动生成。
     *
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    存量用户的 public_id 回填命令
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // ⚠️ comment() 必须在 constrained() **之前**：
            //    后者返回的是 ForeignKeyDefinition（外键定义），不是列定义，
            //    写在它后面 comment 会挂到外键上 —— 迁移不报错，但列上没有备注。
            $table->foreignId('role_id')
                ->nullable()
                ->after('id')
                ->comment('所属角色（单角色）')
                ->constrained('roles')
                ->restrictOnDelete();

            $table->string('public_id', 24)
                ->nullable()
                ->unique()
                ->after('role_id')
                ->comment('对外公开 ID，如 NMX-U-000017；不要给用户看自增 id');

            $table->timestamp('last_seen_at')
                ->nullable()
                ->after('last_login_ip')
                ->comment('最后活跃时间（≠ 最后登录时间）');
        });
    }

    /**
     * 回滚迁移。
     *
     * 用法：
     *   php artisan migrate:rollback
     *
     * 边界/注意：
     *   要**先删外键约束再删列**（MySQL 不允许删掉还被外键引用的列）。
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
            $table->dropForeign(['role_id']);
            $table->dropUnique(['public_id']);
            $table->dropColumn(['role_id', 'public_id', 'last_seen_at']);
        });
    }
};
