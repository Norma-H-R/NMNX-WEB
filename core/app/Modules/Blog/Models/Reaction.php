<?php

declare(strict_types=1);

/**
 * Reaction —— **通用互动**（模块 10，博客与评论共用）
 *
 * 本文件属于 core（纯 API 后端）的 Blog 模块。
 * 用途：一次互动一条记录，`(target_type, target_id, user_id, type)` 唯一索引保证**幂等**
 *       （重复点不会写出第二条，由数据库兜住，不靠调用方自觉）。
 * 谁在调：ReactionService（写）、BlogResource / CommentResource（出口回显 `mine`）。
 *
 * 两套类型**不通用**（规格定死，别合并）：
 *   博客：`like` / `favorite` / `block`   —— block 是"我不想再看这篇"
 *   评论：`like` / `dislike` / `favorite`
 * 所以校验"type 合不合法"时**必须带上 target_type**，不能只看 type。
 *
 * `target_type` 存的是**短键**（`blog` / `comment`），不是类名 ——
 * 将来论坛加 `forum_post` 也只是多一个短键，表结构不动。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/10-blog.md
 */

namespace App\Modules\Blog\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Reaction extends Model
{
    /** 目标类型：博客 */
    public const TARGET_BLOG = 'blog';

    /** 目标类型：评论 */
    public const TARGET_COMMENT = 'comment';

    /**
     * 目标类型：论坛帖子（模块 11 落地时加的**第一个新类型**）。
     *
     * 它证明了一件事：当初把互动做成通用多态是对的 ——
     * 论坛接入**没有动 `reactions` 表结构一行**，只是在这里多一个常量 + 在下面多一个分支。
     */
    public const TARGET_FORUM_POST = 'forum_post';

    /** 博客的互动类型 */
    public const BLOG_TYPES = ['like', 'favorite', 'block'];

    /** 评论的互动类型 */
    public const COMMENT_TYPES = ['like', 'dislike', 'favorite'];

    /**
     * 可批量赋值字段。
     *
     * @var list<string>
     */
    protected $fillable = ['target_type', 'target_id', 'user_id', 'type'];

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
        return ['target_id' => 'integer'];
    }

    /**
     * 取某种目标允许的互动类型。
     *
     * 用法：
     *   Reaction::typesFor(Reaction::TARGET_BLOG);      // ['like','favorite','block']
     *   Reaction::typesFor(Reaction::TARGET_COMMENT);   // ['like','dislike','favorite']
     *   Reaction::typesFor('forum_post');               // []（新类型落地时在这里补）
     *
     * 边界/注意：
     *   返回空数组 = 这个目标类型**还不支持任何互动**。调用方要据此拒绝，
     *   而不是"认不出就当 like" —— 那会把一个非法请求变成一个真实互动。
     *
     * @param  string  $targetType  目标类型短键
     * @return list<string>  允许的类型
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    论坛落地时补 `forum_post` 的类型
     */
    public static function typesFor(string $targetType): array
    {
        return match ($targetType) {
            self::TARGET_BLOG, self::TARGET_FORUM_POST => self::BLOG_TYPES,
            self::TARGET_COMMENT => self::COMMENT_TYPES,
            default => [],
        };
    }

    /**
     * 谁点的。
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
}
