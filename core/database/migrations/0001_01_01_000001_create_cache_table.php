<?php

declare(strict_types=1);

/**
 * 建 cache / cache_locks 两张表（Laravel 骨架 + 本项目补齐的列备注）
 *
 * 为什么这两张表对本项目**很关键**（不是可有可无的框架表）：
 *   我们的 `CACHE_STORE=database`，而权限的**预加载缓存**就存在 `cache` 表里
 *   （键 `nmnx.rbac.registry.v2`）。也就是说鉴权时读的是这张表的**一行**，
 *   而不是每次去 join `permissions` / `roles` / `role_permissions` 三张表。
 *
 * ⚠️ 关于备注：SQLite 不支持列备注，`->comment()` 会被忽略（换 MySQL 才是真备注）。
 *    现在就能查的字段说明见 `docs/database.md`。
 *
 * @version 0.2.0
 *
 * @since   2026-10-03
 * @see     docs/database.md
 * @see     docs/modules/08-rbac.md
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
     *   `expiration` 存的是 **Unix 秒**（不是日期时间），过期判断是拿它和 `time()` 比。
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
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary()->comment('缓存键（主键）。本项目里如 nmnx.rbac.registry.v2');
            $table->mediumText('value')->comment('缓存内容（PHP 序列化后的整包）');
            $table->bigInteger('expiration')->index()->comment('过期时间（Unix 秒）。过期清理与读取都按它判断');
        });

        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary()->comment('被锁的缓存键。与 cache 表同键名，语义是"这个键正在被算"');
            $table->string('owner')->comment('持锁者标识（进程随机串）。解锁时要对得上，防止 A 把 B 的锁放了');
            $table->bigInteger('expiration')->index()->comment('锁的过期时间（Unix 秒）。持锁进程崩了就靠它自动放锁');
        });
    }

    /**
     * 回滚迁移。
     *
     * 用法：
     *   php artisan migrate:rollback
     *
     * 边界/注意：
     *   回滚会**丢掉所有缓存**（含权限预加载）—— 不影响正确性：
     *   下次读不到就回库里重建（见 PermissionRegistry::data()）。
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
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
