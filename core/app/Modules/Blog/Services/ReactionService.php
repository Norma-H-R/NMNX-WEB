<?php

declare(strict_types=1);

/**
 * ReactionService —— 通用互动（模块 10，博客与评论共用）
 *
 * 本文件属于 core（纯 API 后端）的 Blog 模块，按 `docs/modules/10-blog.md` 实现。
 * 用途：点赞 / 收藏 / 拉黑 / 踩 的写入与取消，以及"我点过没"的回显。
 *       博客和评论**共用一张 `reactions` 表**，所以这一层也是共用的。
 * 谁在调：MemberBlogController、CommentController（会员侧的互动接口）。
 *
 * 三条规则（都来自规格，别改）：
 *   1. **三个互动互不互斥**：一个人可以同时点赞 + 收藏。`block`（拉黑）是独立的一票，
 *      语义是"这篇我不想再看到"，**不是**人际关系的拉黑 —— 不影响该作者的其它内容。
 *   2. **幂等**：重复点同一个 type 不报错，直接返回当前状态。前端做了乐观更新，
 *      网络抖动导致的重复提交不能变成报错。靠唯一索引 + 写入前查一次兜住。
 *   3. **不能给自己的内容互动**：后端拦，返回 `BLOG_SELF_REACTION`。
 *      前端也拦一道，但那只为了体验，判定以后端为准。
 *
 * ⚠️ 计数字段（`like_count` 等）**只能在这一层改**：它是冗余列，
 *    一致性靠"写 `reactions` 和改计数在同一个事务里"。别处直接赋值必然对不上账。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/10-blog.md
 */

namespace App\Modules\Blog\Services;

use App\Models\User;
use App\Modules\Blog\Models\Blog;
use App\Modules\Blog\Models\Comment;
use App\Modules\Blog\Models\Reaction;
use App\Modules\Support\ErrorCode;
use App\Modules\Support\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class ReactionService
{
    /** 目标类型：博客 */
    public const TARGET_BLOG = 'blog';

    /** 目标类型：评论 */
    public const TARGET_COMMENT = 'comment';

    /**
     * 每种目标允许的互动类型。
     *
     * ⚠️ 两边的取值**刻意不同**：博客有 `block`（我不想再看到这篇）而没有 `dislike`；
     *    评论有 `dislike`（踩这条回复）而没有 `block`（"拉黑一条评论"没有意义）。
     *    不要在"统一"的名义下把两边凑成一样 —— 语义不同，凑了之后前端就得靠猜。
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_TYPES = [
        self::TARGET_BLOG => ['like', 'favorite', 'block'],
        self::TARGET_COMMENT => ['like', 'dislike', 'favorite'],
    ];

    /**
     * 互动类型 → 目标表上的计数字段。
     *
     * @var array<string, string>
     */
    private const COUNT_COLUMNS = [
        'like' => 'like_count',
        'favorite' => 'favorite_count',
        'block' => 'block_count',
        'dislike' => 'dislike_count',
    ];

    /**
     * 添加一个互动。
     *
     * 用法：
     *   $state = $reactionService->react($user, 'blog', 12, 'like');
     *   // ['stats' => ['like' => 129, 'favorite' => 46, 'block' => 3], 'mine' => ['liked' => true, …]]
     *
     * 边界/注意：
     *   1. 已经点过就直接返回（幂等），**不重复加计数** —— 这是唯一索引之外的第二道保险，
     *      也是前端乐观更新后对账的入口。
     *   2. 写 `reactions` 与改计数在**同一个事务**里：中间挂掉就会出现
     *      "有互动记录但计数没加"（或反之），列表上显示的数字就永久错了。
     *
     * @param  User  $user  当前登录会员
     * @param  string  $targetType  目标类型（`blog` / `comment`）
     * @param  int  $targetId  目标 ID
     * @param  string  $type  互动类型
     * @return array{stats: array<string, int>, mine: array<string, bool>}  互动后的最新状态
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function react(User $user, string $targetType, int $targetId, string $type): array
    {
        $this->assertTypeAllowed($targetType, $type);
        $target = $this->resolveTarget($targetType, $targetId);
        $this->assertNotSelf($user, $target);

        DB::transaction(function () use ($user, $targetType, $targetId, $type, $target): void {
            $already = Reaction::query()
                ->where('target_type', $targetType)
                ->where('target_id', $targetId)
                ->where('user_id', $user->getKey())
                ->where('type', $type)
                ->exists();

            if ($already) {
                return;
            }

            Reaction::create([
                'target_type' => $targetType,
                'target_id' => $targetId,
                'user_id' => $user->getKey(),
                'type' => $type,
            ]);

            $this->changeCount($target, $type, 1);
        });

        return $this->stateOf($user, $targetType, $target);
    }

    /**
     * 取消一个互动。
     *
     * 用法：
     *   $state = $reactionService->unreact($user, 'blog', 12, 'like');
     *
     * 边界/注意：
     *   没点过时同样**不报错**（幂等），也不减计数 —— 否则重复取消会把计数减成负数。
     *   计数列是无符号整型，减到 -1 会直接报数据库错误。
     *
     * @param  User  $user  当前登录会员
     * @param  string  $targetType  目标类型
     * @param  int  $targetId  目标 ID
     * @param  string  $type  互动类型
     * @return array{stats: array<string, int>, mine: array<string, bool>}  互动后的最新状态
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function unreact(User $user, string $targetType, int $targetId, string $type): array
    {
        $this->assertTypeAllowed($targetType, $type);
        $target = $this->resolveTarget($targetType, $targetId);

        DB::transaction(function () use ($user, $targetType, $targetId, $type, $target): void {
            $deleted = Reaction::query()
                ->where('target_type', $targetType)
                ->where('target_id', $targetId)
                ->where('user_id', $user->getKey())
                ->where('type', $type)
                ->delete();

            if ($deleted === 0) {
                return;
            }

            $this->changeCount($target, $type, -1);
        });

        return $this->stateOf($user, $targetType, $target);
    }

    /**
     * 批量回显"我点过没"。
     *
     * 用法：
     *   $mine = $reactionService->mineOf($user, 'blog', [12, 13, 14]);
     *   // [12 => ['like' => true, 'favorite' => false, 'block' => false], …]
     *
     * 边界/注意：
     *   列表页一页 20 条，必须**一次查完**（`whereIn`），不能在循环里逐条查 ——
     *   那是 20 次查询，列表接口会因此慢十倍。
     *   未登录时传 null，直接返回空数组（列表仍要能匿名浏览）。
     *
     * @param  User|null  $user  当前登录会员（未登录传 null）
     * @param  string  $targetType  目标类型
     * @param  list<int>  $targetIds  一批目标 ID
     * @return array<int, array<string, bool>>  目标 ID => 各类型的"我点过没"
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function mineOf(?User $user, string $targetType, array $targetIds): array
    {
        if ($user === null || $targetIds === []) {
            return [];
        }

        $rows = Reaction::query()
            ->where('target_type', $targetType)
            ->whereIn('target_id', $targetIds)
            ->where('user_id', $user->getKey())
            ->get(['target_id', 'type']);

        $types = self::ALLOWED_TYPES[$targetType] ?? [];

        $result = [];
        foreach ($targetIds as $id) {
            $result[$id] = array_fill_keys($types, false);
        }

        foreach ($rows as $row) {
            $result[(int) $row->target_id][$row->type] = true;
        }

        return $result;
    }

    /**
     * 取某个目标的计数快照。
     *
     * 用法：
     *   $stats = $reactionService->statsOf('blog', $blog);
     *
     * 边界/注意：
     *   直接读冗余列，**不** COUNT `reactions` —— 列表页每条都去 COUNT 会拖垮查询，
     *   这正是把计数冗余在表上的原因。
     *
     * @param  string  $targetType  目标类型
     * @param  Model  $target  目标模型（Blog 或 Comment）
     * @return array<string, int>  各类型计数
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function statsOf(string $targetType, Model $target): array
    {
        $stats = [];

        foreach (self::ALLOWED_TYPES[$targetType] ?? [] as $type) {
            $column = self::COUNT_COLUMNS[$type] ?? null;
            $stats[$type] = $column === null ? 0 : (int) $target->getAttribute($column);
        }

        return $stats;
    }

    // ------------------------------------------------------------------
    // 内部辅助
    // ------------------------------------------------------------------

    /**
     * 互动后返回给前端的完整状态（计数 + 我点过没）。
     *
     * @param  User  $user  当前登录会员
     * @param  string  $targetType  目标类型
     * @param  Model  $target  目标模型
     * @return array{stats: array<string, int>, mine: array<string, bool>}  状态
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function stateOf(User $user, string $targetType, Model $target): array
    {
        // 计数在本方法调用前可能刚改过，重新读一次避免拿到内存里的旧值
        $fresh = $target->fresh() ?? $target;

        return [
            'stats' => $this->statsOf($targetType, $fresh),
            'mine' => $this->mineOf($user, $targetType, [(int) $fresh->getKey()])[(int) $fresh->getKey()] ?? [],
        ];
    }

    /**
     * 校验互动类型是否属于该目标类型。
     *
     * @param  string  $targetType  目标类型
     * @param  string  $type  互动类型
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function assertTypeAllowed(string $targetType, string $type): void
    {
        if (! in_array($type, self::ALLOWED_TYPES[$targetType] ?? [], true)) {
            throw new ApiException(ErrorCode::COMMENT_TARGET_INVALID);
        }
    }

    /**
     * 按类型取出目标模型。
     *
     * 用法：
     *   $target = $this->resolveTarget('blog', 12);   // Blog 实例
     *
     * 边界/注意：
     *   只认已发布的博客 —— 给草稿点赞这件事本身没有意义，
     *   而且会泄漏"这篇存在"（返回 404 而不是 403 的同一个理由）。
     *
     * @param  string  $targetType  目标类型
     * @param  int  $targetId  目标 ID
     * @return Blog|Comment  目标模型
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function resolveTarget(string $targetType, int $targetId): Blog|Comment
    {
        return match ($targetType) {
            self::TARGET_BLOG => Blog::query()
                ->whereKey($targetId)
                ->where('status', Blog::STATUS_PUBLISHED)
                ->first() ?? throw new ApiException(ErrorCode::BLOG_NOT_FOUND),
            self::TARGET_COMMENT => Comment::query()->find($targetId)
                ?? throw new ApiException(ErrorCode::COMMENT_NOT_FOUND),
            default => throw new ApiException(ErrorCode::COMMENT_TARGET_INVALID),
        };
    }

    /**
     * 禁止给自己的内容互动。
     *
     * 用法：
     *   $this->assertNotSelf($user, $blog);
     *
     * 边界/注意：
     *   评论的归属看 `user_id`，博客的归属也看 `user_id` —— 两个模型字段名一致，
     *   所以这里能统一处理。将来论坛帖如果字段名不同，这里要跟着改。
     *
     * @param  User  $user  当前登录会员
     * @param  Blog|Comment  $target  目标模型
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function assertNotSelf(User $user, Blog|Comment $target): void
    {
        if ((int) $target->user_id === (int) $user->getKey()) {
            throw new ApiException(ErrorCode::BLOG_SELF_REACTION);
        }
    }

    /**
     * 增减目标上的冗余计数。
     *
     * 用法：
     *   $this->changeCount($blog, 'like', 1);    // like_count + 1
     *
     * 边界/注意：
     *   用 SQL 表达式 `col = col + 1` 而不是"读出来加一再写回"：
     *   后者在并发点赞下会丢更新（两个请求同时读到 10，都写 11，实际应该是 12）。
     *   计数列是无符号整型，所以减之前要保证不会到负数（调用方已判过存在性）。
     *
     * @param  Blog|Comment  $target  目标模型
     * @param  string  $type  互动类型
     * @param  int  $delta  +1 或 -1
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function changeCount(Blog|Comment $target, string $type, int $delta): void
    {
        $column = self::COUNT_COLUMNS[$type] ?? null;

        if ($column === null) {
            return;
        }

        $target->newQuery()
            ->whereKey($target->getKey())
            ->update([$column => DB::raw("{$column} + ({$delta})")]);
    }
}
