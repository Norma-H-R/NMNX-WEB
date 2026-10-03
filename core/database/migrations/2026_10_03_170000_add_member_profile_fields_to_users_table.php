<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 给 users（用户总表）补会员档案字段
 *
 * 本文件属于 core（纯 API 后端）的 Member 模块。
 * 用途：补上**前端已经用到、后端还没有**的两个参数：
 *   tier    会员等级（存**代码**如 `silver`，中文由前端映射 —— 库里不存展示文案）
 *   points  积分
 *
 * 为什么放在 users 总表而不是另开子表：
 *   `users` 就是全站最大的那个节点（会员、授权、订单、博客、论坛都要挂到它上面）。
 *   等级和积分是**会员自身的属性**、且几乎每次读会员都要用，放主表最省 join。
 *   将来如果积分要记流水（谁在什么时候加了多少），那是**另一张流水表**，不是这一列。
 *
 * 取舍说明（用户要求"在这方面做些取舍"）：
 *   - 前端 `AccountUser` 里的 `initials`（头像缩写）**不落库** —— 它能从 `name` 推出来，
 *     存了反而要维护一致性。
 *   - 前端 `joined` 就是 `users.created_at`，**不新增列**。
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
     *   `tier` 默认 `'normal'`（普通会员）。等级体系（几级、叫什么）**还没定**，
     *   所以这里**不建枚举**，先用字符串代码，等体系定下来再补 `MemberTier` 枚举与校验。
     *
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    等级体系确定后，补 `MemberTier` 枚举 + 出参校验
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('tier', 16)->default('normal')->after('status')->comment('会员等级代码，如 normal / silver / gold');
            $table->unsignedInteger('points')->default(0)->after('tier')->comment('积分余额；流水另开表');
        });
    }

    /**
     * 回滚迁移。
     *
     * 用法：
     *   php artisan migrate:rollback
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
            $table->dropColumn(['tier', 'points']);
        });
    }
};
