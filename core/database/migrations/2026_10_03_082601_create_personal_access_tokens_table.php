<?php

declare(strict_types=1);

/**
 * 建 personal_access_tokens 表（Sanctum 令牌表 + 本项目补齐的列备注）
 *
 * 这张表是**会员与管理员共用**的：靠 `tokenable_type` 区分
 *   `App\Models\User`   → 前台会员（`auth:member`）
 *   `...\Auth\Models\Admin` → 后台管理员（`auth:admin`）
 * 「4 层守卫」的隔离就靠它 —— 拿会员令牌去访问 `/admin/*` 会被拒（provider 对不上），
 * 这条有双向的回归测试（`GuardIsolationTest`）。
 *
 * ⚠️ 关于备注：SQLite 不支持列备注，`->comment()` 会被忽略（换 MySQL 才是真备注）。
 *    现在就能查的字段说明见 `docs/database.md`。
 *
 * 为什么把 `morphs('tokenable')` 拆成两列手写：
 *   `morphs()` 一行搞定，但**没法给拆出来的两列挂 `->comment()`**。
 *   手写两列 + 一个索引与它等价（列类型、索引名都与默认一致），换来每列都有说明。
 *
 * @version 0.2.0
 *
 * @since   2026-10-03
 * @see     docs/database.md
 * @see     docs/modules/01-auth.md
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
     *   1. `token` 存的是**哈希**（明文只在签发那一刻返回一次），所以库里这份泄了也不能直接用。
     *   2. `expires_at` 为 null = **永不过期**。本项目目前就是这个状态：
     *      4 小时（会员）/ 24 小时（后台）只在前端本地会话上生效；
     *      要服务端也强制到期，设 `SANCTUM_EXPIRATION`（注意它对**所有**令牌生效，含后台）。
     *
     *
     * @version 0.2.0
     *
     * @since   2026-10-03
     *
     * @todo    服务端令牌过期（SANCTUM_EXPIRATION）
     */
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id()->comment('自增主键');
            $table->string('tokenable_type')->comment('令牌主体类型（模型类名）：App\\Models\\User = 会员，Admin = 管理员');
            $table->unsignedBigInteger('tokenable_id')->comment('令牌主体 ID（对应上面那个模型的主键）');
            $table->text('name')->comment('令牌备注（设备名）。本项目签发时写 member / web / 后台设备名');
            $table->string('token', 64)->unique()->comment('令牌的**哈希**（不是明文）。明文只在签发时返回一次');
            $table->text('abilities')->nullable()->comment('能力范围（JSON 数组）。本项目未使用细粒度能力，恒为 *');
            $table->timestamp('last_used_at')->nullable()->comment('最后使用时间。可用来发现"不用的令牌"和异常调用');
            $table->timestamp('expires_at')->nullable()->comment('过期时间。⚠️ null = 永不过期（本项目现状）');
            $table->timestamp('created_at')->nullable()->comment('签发时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->index(['tokenable_type', 'tokenable_id'], 'personal_access_tokens_tokenable_type_tokenable_id_index');
        });
    }

    /**
     * 回滚迁移。
     *
     * 用法：
     *   php artisan migrate:rollback
     *
     * 边界/注意：
     *   回滚 = **所有人立刻掉线**（所有令牌一起没了）。生产环境别随手跑。
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
        Schema::dropIfExists('personal_access_tokens');
    }
};
