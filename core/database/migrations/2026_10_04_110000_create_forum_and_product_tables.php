<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建论坛（模块 11）与产品（模块 14）的表
 *
 * 本文件属于 core（纯 API 后端）。
 * 用途：
 *   forum_boards  论坛版块
 *   forum_posts   论坛帖子（Markdown 正文，与博客同一套规矩）
 *   products      产品介绍（纯展示，无作者、无软删语义）
 *
 * ⚠️ **字段口径**：库内一律用后端命名（`view_count` / `like_count` / `comment_count` /
 *    `is_pinned` / `body_md`），**前端那套 camelCase（`views`/`likes`/`pin`/`body`）由
 *    Resource 出口时映射**。理由：库里混两套命名，半年后没人分得清哪个是"真字段"。
 *
 * 两条**复用**（这是设计上的关键，别另起一套）：
 *   1. **评论**：直接复用 `comments` 表（`target_type = 'forum_post'`）。
 *      论坛帖的评论树、物化路径、层级折叠、点赞/踩/收藏**一行结构都不用改** ——
 *      当初把评论做成通用多态就是为了这一刻。
 *   2. **互动**：直接复用 `reactions` 表（`target_type = 'forum_post'`）。
 *
 * 缓存策略与博客**相反**（见 `02-content.md`）：论坛**高频写入、详情页不做静态预渲染**，
 * 按帖/按版块失效；**严禁**"每次回复触发全站缓存失效"。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/11-forum.md
 * @see     docs/modules/14-product.md
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
     *   1. `forum_posts.slug` 形如 `t-{boardSlug}-{n}`（前端就是这么生成的），全局唯一。
     *   2. `post_count` / `reply_count` / `last_reply_at` **冗余在版块表上**：
     *      版块列表页要显示"每版多少帖、最后回复时间"，每次 `COUNT(*)` + `MAX()` 会拖垮首页。
     *   3. `forum_posts.status` 给后台审核/下架用（R19），本期只存不判。
     *   4. 产品**没有作者字段**：它是官方维护的展示内容，不是 UGC。
     *
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    论坛"版块 + 版主"（R13）尚未定契约，本表暂不含版主字段
     */
    public function up(): void
    {
        Schema::create('forum_boards', function (Blueprint $table): void {
            $table->id()->comment('自增主键。对外用 slug');
            $table->string('slug', 64)->unique()->comment('对外标识，如 strategy / help');
            $table->string('name', 64)->comment('版块名，如「策略讨论」');
            $table->string('description', 255)->default('')->comment('版块简介');
            $table->unsignedSmallInteger('hue')->default(200)->comment('主题色相（0-360），前端动效的基色');
            $table->unsignedInteger('post_count')->default(0)->comment('帖子数（冗余计数，版块列表要显示）');
            $table->unsignedInteger('reply_count')->default(0)->comment('回复数（冗余计数）');
            $table->timestamp('last_reply_at')->nullable()->comment('最后回复时间；版块列表要按它排');
            $table->unsignedSmallInteger('sort')->default(0)->comment('显示顺序');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');
        });

        Schema::create('forum_posts', function (Blueprint $table): void {
            $table->id()->comment('自增主键。对外用 slug（详情页不做静态预渲染，但 URL 要可读）');
            $table->string('slug', 190)->unique()->comment('对外标识，形如 t-strategy-12');
            $table->foreignId('board_id')->comment('所属版块')->constrained('forum_boards')->cascadeOnDelete();
            $table->foreignId('user_id')->comment('作者（会员总表 users）')->constrained('users')->cascadeOnDelete();
            $table->string('title', 160)->comment('标题');
            $table->string('excerpt', 500)->default('')->comment('列表摘要');
            $table->longText('body_md')->comment('正文 **Markdown 源** —— 唯一真实来源，作者再次编辑用这份');
            $table->longText('body_html')->nullable()->comment('正文渲染好的 HTML（服务端渲染缓存）');
            $table->json('tags')->nullable()->comment('标签数组（JSON），与博客同一套做法');
            $table->boolean('is_pinned')->default(false)->comment('置顶。列表排序规则：置顶在前，其余按 last_reply_at 倒序');
            $table->string('status', 16)->default('normal')->comment('normal 正常 | hidden 官方下架（R19，本期只存不判）');
            $table->unsignedInteger('view_count')->default(0)->comment('浏览数（冗余计数）');
            $table->unsignedInteger('like_count')->default(0)->comment('点赞数（冗余计数）');
            $table->unsignedInteger('comment_count')->default(0)->comment('评论数（冗余计数）。⚠️ 由评论层回填，非同源会不一致');
            $table->timestamp('last_reply_at')->nullable()->comment('最后回复时间（列表排序用）');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');
            $table->softDeletes()->comment('软删时间。删了但保留，否则下面的回复会失去上下文');

            // 帖子列表：按版块筛 + 置顶优先 + 最后回复倒序
            $table->index(['board_id', 'is_pinned', 'last_reply_at'], 'forum_posts_list_index');
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id()->comment('自增主键。对外用 slug');
            $table->string('slug', 64)->unique()->comment('对外标识，如 master-ea');
            $table->string('name', 64)->comment('产品名，如「主控 EA」');
            $table->string('version', 32)->default('')->comment('版本号。详情页顶部动效展示的就是它');
            $table->string('tagline', 255)->default('')->comment('一句话简介（列表页一行）');
            $table->string('status', 16)->default('stable')->comment('stable 稳定版 | beta 测试版 | planned 规划中（中文映射在前端）');
            $table->json('tags')->nullable()->comment('标签数组（JSON）');
            $table->longText('body_md')->comment('详细介绍的 **Markdown 源**');
            $table->longText('body_html')->nullable()->comment('渲染好的 HTML（服务端渲染缓存）');
            $table->unsignedSmallInteger('hue')->default(200)->comment('主题色相（0-360），详情页动效基色');
            $table->unsignedSmallInteger('sort')->default(0)->comment('显示顺序');
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
     *   先删有外键引用的（帖子），再删被引用的（版块）。
     *   ⚠️ 回滚**不会**清 `comments` / `reactions` 里 `target_type='forum_post'` 的行，
     *   那些会变成孤儿数据 —— 演示环境无所谓，生产回滚要另行清理。
     *
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function down(): void
    {
        Schema::dropIfExists('forum_posts');
        Schema::dropIfExists('forum_boards');
        Schema::dropIfExists('products');
    }
};
