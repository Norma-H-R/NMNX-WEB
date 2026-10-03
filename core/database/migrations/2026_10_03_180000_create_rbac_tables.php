<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建 Rbac 的四张表（模块 08）
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块。
 * 用途：把 admin 前端那套权限模型**落库**。契约来源是
 *       `admin/src/types/user.ts` + `mock/permissions.ts` + `mock/roles.ts`
 *       （前端注释写明它是"前后端的契约草稿"）。
 *
 * 为什么权限点和角色要建表、而不是写死在代码里：
 *   前端注释说得很清楚 ——「权限点是可增删改的**数据**，不是常量」。
 *   团队会长出只有自己才懂的权限需求，如果每个都要改代码发版，权限体系就没人愿意用。
 *
 * 四张表的分工：
 *   permissions        权限目录（「模块.动作」能力名 + 显示名 + 分组 + 排序）
 *   roles              角色/身份（名字 + 配色 + 是否隐含全部权限）
 *   role_permissions   角色的**默认权限基线**（角色 × 权限，多对多）
 *   user_permissions   **个人增减**（在角色基线之外多给 / 收回）
 *
 * ⚠️ 关于备注：SQLite **不支持列备注**，`->comment()` 会被静默忽略；
 *    换成 MySQL 之后它是**真的**会写进 `COLUMN_COMMENT`（已实测 100% 覆盖）。
 *    可读版见 `docs/database.md`。
 *
 * ⚠️ 一个踩过的坑（务必注意写法顺序）：
 *   `->constrained()` / `->restrictOnDelete()` 返回的是 **ForeignKeyDefinition**，
 *   不是列定义。`->comment()` 如果写在这之后，**会挂到外键上而不是列上** ——
 *   语法不报错、迁移也过，但那一列在 MySQL 里**没有备注**。
 *   正确顺序：`->comment('…')->constrained(...)`。
 *
 * @version 0.3.0
 *
 * @since   2026-10-03
 * @see     docs/database.md
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
     *   1. `role_permissions` / `user_permissions` 用**数字 ID 做外键**，而不是存权限的 key。
     *      这不是洁癖：权限点**允许改名**（前端就有"改名并同步所有引用"的逻辑）。
     *      存 key 的话，改一次名就要全库同步一遍，漏一处就成了指向不存在权限的"幽灵项"；
     *      存 ID 则改名天然安全。
     *   2. 两个 pivot 都加了 `unique`，防止同一对关系被插两次。
     *   3. 排序用 `group_sort` + `sort` 两个整数：权限目录在后台是一棵两层树，
     *      顺序必须是**确定的**，不能靠自增 ID 碰运气。
     *
     *
     * @version 0.3.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id()->comment('自增主键。⚠️ 改 / 删都用它当路由参数，不要用 key —— key 允许改名');
            $table->string('key', 64)->unique()->comment('能力名，如 forum.post.delete；后端中间件直接用它');
            $table->string('label', 64)->comment('显示名，如「删除帖子」');
            $table->string('description', 255)->default('')->comment('说明，给管理员看的');
            $table->string('group_title', 32)->comment('分组标题，后台按它分块显示');
            $table->unsignedSmallInteger('group_sort')->default(0)->comment('分组顺序');
            $table->unsignedSmallInteger('sort')->default(0)->comment('组内顺序');
            $table->boolean('is_builtin')->default(false)->comment('种子导入的（true）还是界面上新增的（false）');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->index(['group_sort', 'sort'], 'permissions_order_index');
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id()->comment('自增主键。⚠️ 改 / 删都用它当路由参数，不要用 key');
            $table->string('key', 32)->unique()->comment('角色标识，如 admin / custom-1');
            $table->string('name', 32)->comment('显示名，如「论坛版主」');
            $table->string('description', 255)->default('')->comment('说明，给人看的');
            $table->string('tone', 16)->default('slate')->comment('徽章配色：cyan/violet/green/gold/red/slate。⚠️ 只存色号，色值在前端');
            $table->boolean('is_builtin')->default(false)->comment('内置角色（相对自定义）');
            $table->boolean('is_locked')->default(false)->comment('不允许删除（owner / member 是系统依赖）');
            $table->boolean('grants_all')->default(false)->comment('隐含全部权限（owner）—— 不存权限快照，避免权限点增删后过期');
            $table->unsignedSmallInteger('sort')->default(0)->comment('显示顺序');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->id()->comment('自增主键');
            // ⚠️ comment() 必须在 constrained() **之前**，否则会挂到外键上（列上就没备注了）
            $table->foreignId('role_id')->comment('角色')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->comment('权限点')->constrained('permissions')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->unique(['role_id', 'permission_id'], 'role_permissions_unique');
        });

        Schema::create('user_permissions', function (Blueprint $table): void {
            $table->id()->comment('自增主键');
            $table->foreignId('user_id')->comment('用户（总表 users）')->constrained('users')->cascadeOnDelete();
            $table->foreignId('permission_id')->comment('权限点')->constrained('permissions')->cascadeOnDelete();
            $table->boolean('granted')->default(true)->comment('true=额外授予；false=从角色基线里收回');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->unique(['user_id', 'permission_id'], 'user_permissions_unique');
        });
    }

    /**
     * 回滚迁移。
     *
     * 用法：
     *   php artisan migrate:rollback
     *
     * 边界/注意：
     *   先删有外键引用的，再删被引用的 —— 顺序反了会报约束错。
     *
     *
     * @version 0.3.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function down(): void
    {
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
