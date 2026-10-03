<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 给 blog_attachments 补 user_id（模块 10）
 *
 * 本文件属于 core（纯 API 后端）的 Blog 模块。
 *
 * 为什么要有这一次补丁：
 *   `docs/modules/10-blog.md` 的附件表定义里有 `user_id` ——「归属，防止引用别人的文件」。
 *   而 `2026_10_04_100000_create_blog_tables.php` 建表时漏了这一列，且那张迁移**已经执行过**
 *   （migrate:status 显示 Ran），所以不能回头改它 —— 只能追加一次补丁迁移。
 *   这是迁移系统的常规做法：**已执行的迁移永不修改，只往前补**。
 *
 * 为什么是 nullable：
 *   给已有表加列时，表里可能已经有行（上传过的附件）。非空列会让已有行直接失败，
 *   而这里的业务语义也不强求非空（附件先是"上传"出来的，落库关联才发生）。
 *   新写入的记录由 BlogService::syncAttachments 保证一定带上 user_id。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/10-blog.md
 */
return new class extends Migration
{
    /**
     * 执行迁移：补列 + 外键。
     *
     * 用法：
     *   php artisan migrate
     *
     * 边界/注意：
     *   1. 列位置不用 `->after()` —— 那是 MySQL 语法，SQLite 会忽略；
     *      靠 `->after()` 来"让字段看起来整齐"不值得换一次跨库兼容风险。
     *   2. `cascadeOnDelete`：用户注销时他的附件记录一并清掉，
     *      否则会留下指不到人的孤儿行。
     *
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function up(): void
    {
        Schema::table('blog_attachments', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->nullable()
                ->comment('上传者（会员总表 users）。防"引用别人上传的文件"')
                ->constrained('users')
                ->cascadeOnDelete();
        });
    }

    /**
     * 回滚。
     *
     * 用法：
     *   php artisan migrate:rollback --step=1
     *
     * 边界/注意：
     *   必须用 `dropConstrainedForeignId` 而不是 `dropColumn` ——
     *   后者在 SQLite 上会留下一个指向不存在列的索引，下次迁移就报错。
     *
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function down(): void
    {
        Schema::table('blog_attachments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
