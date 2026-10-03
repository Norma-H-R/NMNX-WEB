<?php

declare(strict_types=1);

/**
 * ForumPost —— 论坛帖子（模块 11）
 *
 * 本文件属于 core（纯 API 后端）的 Forum 模块。
 * 用途：会员发的帖子。正文与博客**同一套规矩**：存 Markdown 源 + 服务端渲染的 HTML 缓存。
 * 谁在调：ForumService、`/public/forum/posts*` 接口、后台内容管理（R19）。
 *
 * 与博客的三处**有意不同**（规格定的，别照搬博客）：
 *   1. **高频写入** → 详情页**不做静态预渲染**；缓存按帖/按版块失效，
 *      **严禁**"每次回复就全站失效"；
 *   2. 列表排序固定为 **置顶在前、其余按 `last_reply_at` 倒序**（不是按发帖时间）；
 *   3. 评论与互动**直接复用** `comments` / `reactions` 表（`target_type = 'forum_post'`），
 *      表结构一行都没动。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/11-forum.md
 */

namespace App\Modules\Forum\Models;

use App\Models\User;
use App\Modules\Blog\Models\Reaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ForumPost extends Model
{
    use SoftDeletes;

    /** 状态：正常 */
    public const STATUS_NORMAL = 'normal';

    /** 状态：官方下架（R19） */
    public const STATUS_HIDDEN = 'hidden';

    /**
     * 可批量赋值字段。
     *
     * ⚠️ **不含 `*_count` / `is_pinned` / `last_reply_at`**：计数与置顶由业务逻辑维护，
     * 尤其是 `is_pinned` —— 让发帖接口能直接置顶，等于给了普通会员一个版主能力。
     *
     * @var list<string>
     */
    protected $fillable = ['slug', 'board_id', 'user_id', 'title', 'excerpt', 'body_md', 'body_html', 'tags', 'status'];

    /**
     * 取字段的类型转换表。
     *
     * @return array<string, string>  字段 => cast 规则
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'is_pinned' => 'boolean',
            'last_reply_at' => 'datetime',
        ];
    }

    /**
     * 所属版块。
     *
     * @return BelongsTo<ForumBoard, $this>  版块
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(ForumBoard::class, 'board_id');
    }

    /**
     * 作者。
     *
     * @return BelongsTo<User, $this>  会员总表里的作者
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 本贴的评论（走在**通用评论表**上）。
     *
     * 用法：
     *   $post->comments()->whereNull('parent_id')->count();
     *
     * 边界/注意：
     *   这里必须一起带上 `target_type`，否则会把"评论 id 恰好相同的博客评论"也捞进来 ——
     *   多态表最常见的坑。
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Modules\Blog\Models\Comment, $this>  评论
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function comments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Modules\Blog\Models\Comment::class, 'target_id')
            ->where('target_type', Reaction::TARGET_FORUM_POST);
    }
}
