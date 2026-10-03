<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 回填存量用户的公开 ID 与最后活跃时间
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块。
 * 用途：`public_id` 是上一条迁移才加的列，**模型钩子只对新用户生效** ——
 *       已经存在的人（比如本地那个测试会员）会一直是 NULL，接口就会吐 `public_id: null`。
 *       这条迁移把存量补齐。
 *
 * 为什么单独一条迁移而不是塞进上一条：
 *   上一条在本地已经跑过了，改它对已经迁移过的库没有任何作用。
 *   数据回填属于"一次性动作"，独立成迁移最清楚。
 *
 * 为什么用 PHP 循环而不是一条 SQL：
 *   SQLite 的字符串拼接是 `||` + 没有 `LPAD`，MySQL 是 `CONCAT` + `LPAD` ——
 *   写一条方言 SQL 就等于把"不写方言"的规矩破掉。PHP 循环慢一点，但两边都能跑。
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
     *   1. 只处理 `public_id` 为空的，已经是好的不动 —— 迁移要能重复跑而不破坏数据。
     *   2. `last_seen_at` 用 `last_login_at` 近似（我们手上只有这个信息）：
     *      "至少这个人登录过"。真正的活跃时间从审计模块开始记。
     *   3. 公开 ID 由自增 id 派生，所以**不能**用批量的 `update ... set`，
     *      要按行算（不同行不同值）。这里用 `chunkById` 避免一次性把整表拉进内存。
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
        DB::table('users')
            ->whereNull('public_id')
            ->orderBy('id')
            ->chunkById(200, function (iterable $rows): void {
                foreach ($rows as $row) {
                    DB::table('users')
                        ->where('id', $row->id)
                        ->update([
                            'public_id' => User::PUBLIC_ID_PREFIX
                                .str_pad((string) $row->id, 6, '0', STR_PAD_LEFT),
                        ]);
                }
            });

        DB::table('users')
            ->whereNull('last_seen_at')
            ->whereNotNull('last_login_at')
            ->update(['last_seen_at' => DB::raw('last_login_at')]);
    }

    /**
     * 回滚迁移。
     *
     * 用法：
     *   php artisan migrate:rollback
     *
     * 边界/注意：
     *   回填是**不可逆**的（我们不知道哪个 public_id 是原本就有的），
     *   所以这里什么都不做 —— 清空反而可能删掉真实数据。
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
        // 故意留空：见上方说明
    }
};
