<?php

declare(strict_types=1);

/**
 * 建 users / password_reset_tokens / sessions 三张表（Laravel 骨架 + 本项目补齐的列备注）
 *
 * ⚠️ 关于备注：**SQLite 不支持列备注**，`->comment()` 会被静默忽略 ——
 *    也就是说这些备注现在在 SQLite 里翻不到。补它的理由是：
 *      1) 将来换 MySQL 就是**真备注**（`MODIFY COLUMN ... COMMENT`），DB 工具里直接能看；
 *      2) 迁移文件本身变成自解释的，改表的人不用去猜字段。
 *    想**现在就能查**字段含义，看 `docs/database.md`（那份是引擎无关的）。
 *
 * users 后来还被这几条迁移改过（按时间顺序，别只看本文件）：
 *   2026_10_03_160000  add_member_fields_to_users_table        phone / status / last_login_*
 *   2026_10_03_170000  add_member_profile_fields_to_users_table tier / points
 *   2026_10_03_180100  align_users_with_rbac                    role_id / public_id / last_seen_at
 *   2026_10_03_180200  backfill_users_public_id                 回填存量行的公开 ID
 *
 * @version 0.2.0
 *
 * @since   2026-10-03
 * @see     docs/database.md
 * @see     docs/modules/07-member.md
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 执行迁移。
     *
     * 用法：
     *   php artisan migrate
     *
     * 边界/注意：
     *   1. 这里的 `users` 只是**认证骨架**（最小集合）。会员档案、角色关联都在后续迁移里加 ——
     *      **不要**往本文件里补列：已经迁移过的库不会重跑它，补在这里等于没补。
     *   2. 用显式的 `timestamp('created_at')` 而不是 `timestamps()`：
     *      后者不返回列对象，**没法挂 `->comment()`**。
     *
     *
     * @version 0.2.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id()->comment('自增主键。⚠️ 仅内部逻辑用，**不要展示给用户** —— 对外用 public_id');
            $table->string('name')->comment('昵称 / 前台显示名。注册时留空则由邮箱前缀兜底');
            $table->string('email')->unique()->comment('邮箱。登录标识之一（与 phone 二选一），全表唯一');
            $table->timestamp('email_verified_at')->nullable()->comment('邮箱验证时间；null = 还没验证过');
            $table->string('password')->comment('密码哈希。模型有 hashed cast，赋值明文会自动哈希，别再手动 Hash::make');
            $table->string('remember_token', 100)->nullable()->comment('"记住我"令牌。⚠️ API 场景恒为空：我们走 Bearer 令牌，不用 session');
            $table->timestamp('created_at')->nullable()->comment('创建时间。前端要的"加入时间"就是它，不要另加 joined_at');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary()->comment('目标邮箱（主键）。一个邮箱同时只有一条重置记录');
            $table->string('token')->comment('重置令牌的哈希。明文只在邮件里出现一次，库里不存明文');
            $table->timestamp('created_at')->nullable()->comment('发起时间。用于判断令牌是否过期');
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary()->comment('会话 ID');
            $table->foreignId('user_id')->nullable()->comment('登录用户；未登录的访客为 null')->index();
            $table->string('ip_address', 45)->nullable()->comment('客户端 IP。45 字符是为了放得下 IPv6');
            $table->text('user_agent')->nullable()->comment('浏览器 UA 原文');
            $table->longText('payload')->comment('会话数据（序列化后的整包）');
            $table->integer('last_activity')->index()->comment('最后活跃时间（Unix 秒）。过期清理按它判断');
        });
    }

    /**
     * 回滚迁移。
     *
     * 用法：
     *   php artisan migrate:rollback
     *
     * 边界/注意：
     *   三张表互相没有外键，删除顺序无所谓。
     *
     *
     * @version 0.2.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
