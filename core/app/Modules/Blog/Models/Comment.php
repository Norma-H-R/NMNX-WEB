<?php

declare(strict_types=1);

/**
 * Comment —— **通用评论**（模块 10，**不是博客的附属功能**）
 *
 * 本文件属于 core（纯 API 后端）的 Blog 模块。
 * 用途：任意对象的评论树。用 `target_type` + `target_id` 挂载，
 *       存储用 `parent_id` + **物化路径 `path`**，**无限嵌套**。
 * 谁在调：CommentService（写）、`/api/v1/comments` 接口、CommentResource。
 *
 * ⚠️ **论坛将来要直接复用它** —— 所以这里**不许出现任何"博客专属"的东西**：
 *   `target_type` 加一个 `forum_post` 就能用，**表结构一行不用动**。
 *
 * 取子树为什么用 `path LIKE '0000012/%'` 而不是递归：
 *   一次查询拿完整棵子树。递归在"某条热评下面几百条回复"时会打出 N+1，
 *   而列表页一次要展示几十棵树 —— 递归版本会直接把请求打爆。
 *
 * **存储无限、展示有度**：`depth` 冗余存层级，前端超过 6 层折叠成"继续查看"
 *   （无限缩进在窄屏上根本没法看，Reddit 自己也这么做）。
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

final class Comment extends Model
{
    use SoftDeletes;

    /** 展示时最多展开的层级（再深就折叠成"继续查看"） */
    public const MAX_DISPLAY_DEPTH = 6;

    /**
     * 可批量赋值字段。
     *
     * ⚠️ 这里**不含 `*_count` 与 `path` / `depth`**：
     *    计数只能由互动逻辑改；`path`/`depth` 由 CommentService 算（算错整棵树就乱了）。
     *
     * @var list<string>
     */
    protected $fillable = ['target_type', 'target_id', 'user_id', 'parent_id', 'body'];

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
            'target_id' => 'integer',
            'parent_id' => 'integer',
            'depth' => 'integer',
        ];
    }

    /**
     * 评论者。
     *
     * @return BelongsTo<User, $this>  会员总表里的用户
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * 直接父评论（顶层为 null）。
     *
     * @return BelongsTo<Comment, $this>  父评论
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * 直接子回复。
     *
     * @return HasMany<Comment, $this>  子评论
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * 算一条评论的物化路径。
     *
     * 用法：
     *   Comment::buildPath(null, 12);    // '0000012/'
     *   Comment::buildPath('0000012/', 451); // '0000012/0000451/'
     *
     * 边界/注意：
     *   每段**固定 7 位零填充**。这是让 `path LIKE '0000012/%'` 不误伤的关键 ——
     *   不填充的话 `LIKE '12/%'` 会命中 `120/…`，那棵树就串了。
     *
     * @param  string|null  $parentPath  父评论的 path（顶层传 null）
     * @param  int  $id  本条评论的 id（**必须先落库拿到 id** 才能算 path）
     * @return string  物化路径，形如 `0000012/0000451/`
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public static function buildPath(?string $parentPath, int $id): string
    {
        return ($parentPath ?? '').str_pad((string) $id, 7, '0', STR_PAD_LEFT).'/';
    }
}
