<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建博客（模块 10）的四张表
 *
 * 本文件属于 core（纯 API 后端）的 Blog 模块，**严格按 `docs/modules/10-blog.md` 实现**。
 * 用途：
 *   blogs             会员写的博客（正文存 Markdown 源 + 渲染好的 HTML）
 *   blog_attachments  附件（按 MIME 判定 kind，供前端分流展示）
 *   reactions         **通用互动**（博客的 like/favorite/block、评论的 like/dislike/favorite 共用）
 *   comments          **通用评论**（多态挂载 + 物化路径，论坛将来直接复用）
 *
 * 四条关键决策（都来自规格，别改）：
 *   1. **博客 ≠ 文章**。博客是会员 UGC，文章是官方发布（模块 02），两张表两套接口。
 *   2. **正文存两份**：`body_md` 是唯一真实来源（作者再次编辑要用它），
 *      `body_html` 是保存时用服务端渲染器生成的缓存（列表/详情直接吐，免得每次渲染）。
 *   3. **互动三态独立、不互斥**：`like`/`favorite` 是正向，`block` 是负向；
 *      一个人可以同时点赞+收藏。用 `(target_type,target_id,user_id,type)` 唯一索引兜重复。
 *   4. **评论与互动都是"通用"的**：用 `target_type + target_id` 挂到任意对象上。
 *      论坛落地时只需加一个 `forum_post` 类型，**表结构一行不用动** ——
 *      所以这里**不许出现任何"博客专属"的字段名**（比如 `blog_id`）。
 *
 * 计数冗余（`*_count`）：列表页要"一眼看到多少赞"，每次 COUNT(*) 会拖垮列表查询，
 * 所以冗余在表上；一致性靠"互动写入在同一事务里 +1/-1"（对账命令 `blog:recount` 待做）。
 *
 * ⚠️ 这些表的列**全部带 `->comment()`**：迁到 MySQL 之后是真备注，逐列可查
 * （SQLite 不支持，会被忽略）。可读版见 `docs/database.md`。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/10-blog.md
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
     *   1. `tags` 用 **JSON 列**：标签是小规模、只读多写少的字符串列表，
     *      单独开 `tags` + `blog_tags` 两张表带来的收益抵不过每次都要 join 的代价。
     *      MySQL 有原生 JSON，SQLite 存文本 + 模型 `array` cast，两边都能跑。
     *   2. `slug` 全局唯一、**发布后不可改**（改了旧链接就断 —— 详情页要静态预渲染）。
     *   3. `path` 形如 `0000012/0000451/`（每段 7 位零填充）。
     *      取某条评论的整棵子树 = `path LIKE '0000012/%'`，**一次查询拿完，不用递归**。
     *   4. `allow_reference` 是给论坛"引用博客"预留（R14），本期只有这一列。
     *
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    `blog:recount` 对账命令
     */
    public function up(): void
    {
        Schema::create('blogs', function (Blueprint $table): void {
            $table->id()->comment('自增主键。对外用 slug，不用它 (详情页 URL 要可读)');
            $table->foreignId('user_id')->comment('作者（会员总表 users）')->constrained('users')->cascadeOnDelete();
            $table->string('slug', 190)->unique()->comment('对外标识，全局唯一；发布后不可改（改了旧链接就断）');
            $table->string('title', 160)->comment('标题');
            $table->string('excerpt', 500)->default('')->comment('摘要（列表卡片用，2 行截断）');
            $table->longText('body_md')->comment('正文 **Markdown 源** —— 唯一真实来源，作者再次编辑用这份');
            $table->longText('body_html')->nullable()->comment('正文渲染好的 HTML（服务端渲染的缓存，列表/详情直接吐，省得每次渲染）');
            $table->string('cover_url', 500)->nullable()->comment('封面地址。**草稿可为空**，发布时校验必填（前端渲染占位块）');
            $table->json('tags')->nullable()->comment('标签数组（JSON）。小规模列表，不值得为它开两张表 join');
            $table->string('status', 16)->default('draft')->comment('draft 草稿 | published 已发布 | hidden 官方下架');
            $table->unsignedInteger('view_count')->default(0)->comment('阅读数（冗余计数，列表页要一眼看到）');
            $table->unsignedInteger('like_count')->default(0)->comment('点赞数（冗余计数，同事务 +1/-1）');
            $table->unsignedInteger('favorite_count')->default(0)->comment('收藏数（冗余计数）');
            $table->unsignedInteger('block_count')->default(0)->comment('被拉黑数。**只有作者自己看得到**，不公开');
            $table->unsignedInteger('comment_count')->default(0)->comment('评论数（冗余计数）');
            $table->boolean('allow_reference')->default(true)->comment('允许被论坛帖引用（R14 预留，本期只存不判）');
            $table->timestamp('published_at')->nullable()->comment('发布时间；草稿为 null');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');
            $table->softDeletes()->comment('软删时间。删了但可恢复，且不影响已关联的评论/互动');

            $table->index(['status', 'published_at'], 'blogs_list_index');
            $table->index('user_id', 'blogs_author_index');
        });

        Schema::create('blog_attachments', function (Blueprint $table): void {
            $table->id()->comment('自增主键');
            $table->foreignId('blog_id')->comment('所属博客')->constrained('blogs')->cascadeOnDelete();
            $table->string('kind', 16)->comment('展示类型：image|audio|video|doc|sheet|pdf|other（**由后端按 MIME 判定**，前端只管画）');
            $table->string('url', 500)->comment('文件地址');
            $table->string('name', 255)->comment('原始文件名（展示用）');
            $table->unsignedBigInteger('size')->default(0)->comment('字节数（前端显示为 KB/MB）');
            $table->string('mime', 128)->default('')->comment('MIME 原文。kind 是从它推出来的，留着便于以后重新归类');
            $table->unsignedSmallInteger('sort')->default(0)->comment('同篇内的展示顺序');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->index(['blog_id', 'sort'], 'blog_attachments_order_index');
        });

        Schema::create('reactions', function (Blueprint $table): void {
            $table->id()->comment('自增主键');
            // ⚠️ comment() 必须在 constrained() 之前 —— 写在后面会挂到外键上，列上就没备注了
            $table->string('target_type', 32)->comment('目标类型：blog | comment（**通用表，论坛落地时加新类型即可，表结构不动**）');
            $table->unsignedBigInteger('target_id')->comment('目标 ID（配合 target_type 指向任意对象）');
            $table->foreignId('user_id')->comment('谁点的（会员总表 users）')->constrained('users')->cascadeOnDelete();
            $table->string('type', 16)->comment('互动类型：博客用 like|favorite|block；评论用 like|dislike|favorite');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            // 同一人同一对象同一类型只有一条 —— 重复点由它兜住（幂等）
            $table->unique(['target_type', 'target_id', 'user_id', 'type'], 'reactions_unique');
            $table->index(['target_type', 'target_id'], 'reactions_target_index');
        });

        Schema::create('comments', function (Blueprint $table): void {
            $table->id()->comment('自增主键。**这条 id 也是物化路径 path 里的一段**');
            $table->string('target_type', 32)->comment('目标类型：blog | forum_post…（通用，论坛复用同一套）');
            $table->unsignedBigInteger('target_id')->comment('目标 ID');
            $table->foreignId('user_id')->comment('评论者（会员总表 users）')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable()->comment('父评论；顶层为 null');
            $table->string('path', 255)->default('')->comment('物化路径，如 0000012/0000451/。取整棵子树用 LIKE 一次查完，不用递归');
            $table->unsignedSmallInteger('depth')->default(0)->comment('层级（冗余）。前端折叠与后端限制都用它，省得数斜杠');
            $table->text('body')->comment('评论正文（纯文本，不支持 Markdown —— 评论里塞 Markdown 是 XSS 重灾区）');
            $table->unsignedInteger('like_count')->default(0)->comment('赞数（冗余计数）');
            $table->unsignedInteger('dislike_count')->default(0)->comment('踩数（冗余计数）');
            $table->unsignedInteger('favorite_count')->default(0)->comment('收藏数（冗余计数）');
            $table->unsignedInteger('reply_count')->default(0)->comment('直接子回复数（前端"查看 N 条回复"用）');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');
            $table->softDeletes()->comment('软删时间。删了但保留在树上（显示"该评论已删除"），否则下面的回复会失去上下文');

            // 取某篇博客的顶层评论（parent_id 为空）按时间/热度排 —— 最常用的查询
            $table->index(['target_type', 'target_id', 'parent_id'], 'comments_target_index');
            // 取子树：path LIKE '0000012/%'
            $table->index('path', 'comments_path_index');
        });
    }

    /**
     * 回滚迁移。
     *
     * 用法：
     *   php artisan migrate:rollback
     *
     * 边界/注意：
     *   先删有外键引用的（附件、互动、评论），再删被引用的（博客）。
     *
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function down(): void
    {
        Schema::dropIfExists('comments');
        Schema::dropIfExists('reactions');
        Schema::dropIfExists('blog_attachments');
        Schema::dropIfExists('blogs');
    }
};
