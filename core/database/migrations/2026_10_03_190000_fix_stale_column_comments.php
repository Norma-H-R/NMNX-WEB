<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 修正三条**陈述过时**的列备注
 *
 * 本文件属于 core（纯 API 后端）的 Member / Support 模块。
 * 用途：迁移里的 `->comment()` 是**历史快照** —— 写在当时是对的，但业务改了之后
 *       它不会自动跟着变。迁到 MySQL 后逐列读了一遍，抓到这三条在说假话：
 *
 *   | 列 | 旧备注（错） | 为什么错 |
 *   |---|---|---|
 *   | `users.status` | `active \| disabled` | 早已改成**三值**：`active` / `muted`（禁言，仍可登录）/ `banned`（封禁，不能登录） |
 *   | `users.phone` | "预留给短信登录" | 它**已经是登录标识**了（手机号登录 + 注册都能用），不是"预留" |
 *   | `cache.key` | "本项目里如 `nmnx.rbac.registry.v2`" | Rbac 预加载的缓存键**已经升到 v3**，写死版本号必然过时 |
 *
 * 为什么用 `change()` 而不是改老迁移：
 *   改老迁移对**已经建好的库**没有任何作用（它不会重跑），只对新装有效；
 *   而 `change()` 写在新迁移里，**两边都覆盖**（新装时它排在老迁移之后）。
 *
 * 为什么不用 `migrate:fresh`：
 *   那会把数据全清掉（包括你当前的登录令牌），代价太大 —— `change()` 只动列定义，数据一行不动。
 *
 * ⚠️ 写 `change()` 的两个坑（都避开了）：
 *   1. **必须带上原来的属性**（`nullable()` / `default()`），否则光写 `->comment()` 会把列改成 NOT NULL；
 *   2. **不要带 `unique()` / `index()`** —— `change()` 只管列本身，带上会让索引被重复创建而报错。
 *
 * @version 0.1.0
 * @since   2026-10-03
 * @see     docs/database.md
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
     *   SQLite **不支持列备注**，在 SQLite 上跑这条等于空转（不会报错，也不会有备注）——
     *   所以它主要是给 MySQL 用的。测试跑在 SQLite 内存库上，因此这条**没有测试覆盖**，
     *   靠"迁移能跑通 + 逐列读出来核对"来验证。
     *
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-03
     * @todo    无
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('status', 16)
                ->default('active')
                ->comment('active（正常）| muted（**禁言，仍可登录**）| banned（封禁，不能登录）；见 MemberStatus')
                ->change();

            $table->string('phone', 20)
                ->nullable()
                ->comment('手机号，登录标识之一（与 email 二选一），唯一；测试期统一填 11111')
                ->change();
        });

        // 不再写死版本号：缓存键会随数据结构升级（v1 → v2 → v3…），写死必然过时
        Schema::table('cache', function (Blueprint $table): void {
            $table->string('key')
                ->comment('缓存键（主键）。本项目里的键名都以 nmnx. 开头，如权限预加载 nmnx.rbac.registry.*')
                ->change();
        });
    }

    /**
     * 回滚迁移。
     *
     * 用法：
     *   php artisan migrate:rollback
     *
     * 边界/注意：
     *   把备注**改回原来那三条错的**没有意义，所以这里只把格式修回去（去掉过时描述）。
     *
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-03
     * @todo    无
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('status', 16)->default('active')->comment('账号状态')->change();
            $table->string('phone', 20)->nullable()->comment('手机号')->change();
        });

        Schema::table('cache', function (Blueprint $table): void {
            $table->string('key')->comment('缓存键（主键）')->change();
        });
    }
};
