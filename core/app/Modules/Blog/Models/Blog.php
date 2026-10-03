<?php

declare(strict_types=1);

/**
 * Blog —— 会员写的博客（模块 10）
 *
 * 本文件属于 core（纯 API 后端）的 Blog 模块，按 `docs/modules/10-blog.md` 实现。
 * 用途：博客主表。**博客 ≠ 文章** —— 博客是会员 UGC，文章是官方发布（模块 02 的 content），
 *       两张表两套接口，别混。
 * 谁在调：BlogService（写）、BlogResource（出口）、后台的内容管理（模块 10 的 admin 侧）。
 *
 * 两个必须记住的点：
 *   1. **`body_md` 是唯一真实来源**，`body_html` 只是服务端渲染出来的缓存（保存时重算）。
 *      作者再次编辑要用 `body_md`；前端详情页吃 `body_html`（避免每次请求都渲染一遍）。
 *   2. **计数是冗余的**（`like_count` 等）：列表页要"一眼看到多少赞"，
 *      每次 COUNT(*) 会拖垮列表。一致性靠"互动写入时在同一事务里 ±1"。
 *      所以**不要**在别处直接改这些计数，统一走 BlogService / ReactionService。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/10-blog.md
 */

namespace App\Modules\Blog\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Blog extends Model
{
    use SoftDeletes;

    /** 状态：草稿 */
    public const STATUS_DRAFT = 'draft';

    /** 状态：已发布 */
    public const STATUS_PUBLISHED = 'published';

    /** 状态：官方下架（⛔ 后台审核用，本期只定义） */
    public const STATUS_HIDDEN = 'hidden';

    /**
     * 可批量赋值字段。
     *
     * ⚠️ 这里**故意不含 `*_count`**：计数只能由互动逻辑在同一事务里改，
     *    放开批量赋值等于给"凭空改计数"开了口子。
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'slug',
        'title',
        'excerpt',
        'body_md',
        'body_html',
        'cover_url',
        'tags',
        'status',
        'allow_reference',
        'published_at',
    ];

    /**
     * 取字段的类型转换表。
     *
     * 用法：
     *   $blog->tags;          // array（JSON 列自动转数组）
     *   $blog->published_at;  // Carbon 或 null
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
            'allow_reference' => 'boolean',
            'published_at' => 'datetime',
        ];
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
     * 附件（图片/音视频/文档…）。
     *
     * @return HasMany<BlogAttachment, $this>  附件集合（按 sort 排）
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(BlogAttachment::class)->orderBy('sort');
    }

    /**
     * 判断是否已发布（详情页/列表对外可见的判据）。
     *
     * 用法：
     *   $blog->isPublished();
     *
     * 边界/注意：
     *   用 `status` 判，**不要**用 `published_at` 是否为空 —— 定时发布（`article.schedule`
     *   那种）将来会让"有发布时间但还没到点"成为合法状态。
     *
     * @return bool  已发布返回 true
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
