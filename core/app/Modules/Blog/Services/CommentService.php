<?php

declare(strict_types=1);

/**
 * CommentService —— 通用评论（模块 10，博客与论坛共用）
 *
 * 本文件属于 core（纯 API 后端）的 Blog 模块，按 `docs/modules/10-blog.md` 实现。
 * 用途：评论树的读、写、删。用 `target_type` + `target_id` 挂到任意对象上。
 * 谁在调：CommentController（`/api/v1/comments`）。
 *
 * ⚠️ 这一层**不许出现"博客"这个词** —— 论坛落地时只换一个 `target_type`，
 *    这里一行都不用改。任何"博客专属"的判断（比如"博客的评论要审核"）都要放在别处。
 *
 * 两个关键做法：
 *   1. **取子树用 `path LIKE '0000012/%'` 一次查完**，再在内存里拼树。
 *      递归查询在"某条热评下面几百条回复"时会打出 N+1，而列表页一次要展示几十棵树 ——
 *      递归版本会直接把请求打爆。
 *   2. **存储无限、展示有度**：数据库里层级不设上限，
 *      但一次返回只展开到 `Comment::MAX_DISPLAY_DEPTH` 层，更深的带 `has_more`，
 *      前端点"继续查看"时再按 `parent_id` 拉一段。无限缩进在窄屏上根本没法看。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/10-blog.md
 */

namespace App\Modules\Blog\Services;

use App\Models\User;
use App\Modules\Blog\Models\Blog;
use App\Modules\Blog\Models\Comment;
use App\Modules\Support\ErrorCode;
use App\Modules\Support\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CommentService
{
    /** 顶层评论每页条数 */
    private const PER_PAGE = 20;

    /**
     * 构造：注入通用互动服务。
     *
     * 为什么评论要依赖互动：
     *   评论自带赞 / 踩 / 收藏（模块文档决策 10），出参里的 `mine`（我点过没）
     *   必须一并给出。把它交给 ReactionService 算，而不是在这里重写一遍查询 ——
     *   "我点过没"的规则（同一张 `reactions` 表、同一套类型取值）只该有一处实现。
     *
     * @param  ReactionService  $reactions  通用互动服务
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function __construct(
        private readonly ReactionService $reactions,
    ) {}

    /**
     * 取某个对象的评论树。
     *
     * 用法：
     *   $tree = $commentService->tree('blog', 12, ['sort' => 'hot']);
     *   // ['total' => 42, 'items' => [ ['id'=>451, …, 'children'=>[…] ], … ]]
     *
     * 边界/注意：
     *   1. **顶层分页、子级全带**。子级不分页：读者展开一条评论是想读完整串对话，
     *      中间再插一次分页会把对话截断。
     *   2. 只在**内存里**拼树，且按 `depth` 截到 6 层（更深的 `has_more = true`）。
     *   3. 排序 `hot` 按 `like_count`，`new` 按时间 —— 只影响**顶层**的顺序，
     *      子级一律按时间正序（对话的时间线不能乱）。
     *
     * @param  string  $targetType  目标类型（`blog` / `comment`）
     * @param  int  $targetId  目标 ID
     * @param  array{sort?: string|null}  $filters  排序等
     * @param  User|null  $viewer  当前访问者（未登录传 null，此时所有 `mine` 为 null）
     * @return array{total: int, items: array<int, array<string, mixed>>}  评论树
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function tree(string $targetType, int $targetId, array $filters = [], ?User $viewer = null): array
    {
        $page = $this->paginateTopLevel($targetType, $targetId, $filters);
        $tops = $page->getCollection();

        // 顶层的 path 列表：用它一次性把"这些顶层底下所有的回复"捞出来
        $paths = $tops->pluck('path')->filter()->values()->all();
        $descendants = $this->loadDescendants($targetType, $targetId, $paths);

        // "我赞过没"合成**一批**去查：顶层 + 全部子孙的 id。一棵热评树有几十条评论，
        // 在节点里逐条查就是几十次查询 —— 列表页一次要展示几十棵树，会直接把请求打爆
        $ids = array_map(
            static fn ($id): int => (int) $id,
            array_merge($tops->modelKeys(), $descendants->modelKeys()),
        );

        $mineMap = $viewer === null
            ? []
            : $this->reactions->mineOf($viewer, ReactionService::TARGET_COMMENT, $ids);

        return [
            'total' => $page->total(),
            'items' => $this->assemble($tops, $descendants, $mineMap),
        ];
    }

    /**
     * 发表评论或回复。
     *
     * 用法：
     *   $comment = $commentService->create($user, [
     *       'target_type' => 'blog', 'target_id' => 12, 'parent_id' => 451, 'body' => '…',
     *   ]);
     *
     * 边界/注意：
     *   1. `path` 与 `depth` **依赖本条评论自己的 id**，所以只能先落库、拿到 id 再回填。
     *      两次 `save()` 包在同一事务里，外部看不到中间态。
     *   2. 父评论必须属于**同一个目标**，否则会出现"这条回复挂在另一个对象的树里"
     *      （返回 `COMMENT_PARENT_MISMATCH`）。
     *   3. 父的 `reply_count` 与目标的 `comment_count` 都要在同一事务里 +1 ——
     *      它们都是冗余列，漏了就会出现"评论数永远是 0"。
     *
     * @param  User  $user  评论者
     * @param  array{target_type: string, target_id: int|string, parent_id?: int|string|null, body: string}  $data  已校验入参
     * @return Comment 新建的评论
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function create(User $user, array $data): Comment
    {
        $targetType = (string) $data['target_type'];
        $targetId = (int) $data['target_id'];
        $parentId = isset($data['parent_id']) ? (int) $data['parent_id'] : null;

        return DB::transaction(function () use ($user, $data, $targetType, $targetId, $parentId): Comment {
            $parent = $this->resolveParent($parentId, $targetType, $targetId);

            $comment = new Comment;
            $comment->target_type = $targetType;
            $comment->target_id = $targetId;
            $comment->user_id = $user->getKey();
            $comment->parent_id = $parentId;
            $comment->body = $data['body'];
            $comment->save();

            // 到这里才有 id，才谈得上算 path
            $comment->path = Comment::buildPath($parent?->path, (int) $comment->getKey());
            $comment->depth = $parent === null ? 1 : (int) $parent->depth + 1;
            $comment->save();

            if ($parent !== null) {
                $this->bumpReplyCount($parent, 1);
            }

            $this->bumpTargetCommentCount($targetType, $targetId, 1);

            return $comment->load('user:id,name,public_id');
        });
    }

    /**
     * 删除评论。
     *
     * 用法：
     *   $commentService->delete($user, $comment);
     *
     * 边界/注意：
     *   1. **只能软删，且不清子孙**。物理删掉父评论会让子评论变成"找不到爹的孤儿"
     *      （`parent_id` 指向一条不存在的记录），整棵树在页面上就断了。
     *      正确做法是把正文清空、保留树形，前端渲染成"该评论已删除"。
     *   2. 有权限的是两类人：评论作者本人，以及**目标对象的作者**
     *      （"我名下的内容，我可以删掉别人对我的评论"）。
     *
     * @param  User  $user  当前登录会员
     * @param  Comment  $comment  目标评论
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function delete(User $user, Comment $comment): void
    {
        $isAuthor = (int) $comment->user_id === (int) $user->getKey();

        if (! $isAuthor && ! $this->isTargetOwner($user, $comment)) {
            // 同样用 404 而不是 403：不确认"这条评论存在"
            throw new ApiException(ErrorCode::COMMENT_NOT_FOUND);
        }

        DB::transaction(function () use ($comment): void {
            // 先清正文再软删：列表里那条会显示成"该评论已删除"，
            // 但子回复仍然挂在它下面，树不会断
            $comment->body = '';
            $comment->save();
            $comment->delete();

            if ($comment->parent_id !== null) {
                $parent = Comment::query()->find($comment->parent_id);
                if ($parent !== null) {
                    $this->bumpReplyCount($parent, -1);
                }
            }

            $this->bumpTargetCommentCount($comment->target_type, (int) $comment->target_id, -1);
        });
    }

    /**
     * 取某个目标的所有评论者 ID（通知中心用）。
     *
     * 用法：
     *   $ids = $commentService->participantIds('blog', 12);
     *
     * 边界/注意：
     *   去重后返回。通知要发给"参与过这个话题的人"，
     *   同一个人回复了十条只该收到一条通知。
     *
     * @param  string  $targetType  目标类型
     * @param  int  $targetId  目标 ID
     * @return list<int>  去重后的用户 ID
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    接入通知中心时使用（本期未接）
     */
    public function participantIds(string $targetType, int $targetId): array
    {
        return Comment::query()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->distinct()
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * 单条评论的出参形状（与树里的节点逐字一致）。
     *
     * 用法：
     *   $payload = $commentService->toPayload($comment, ['liked' => true, …]);
     *
     * 边界/注意：
     *   1. `mine`（我赞过没）由调用方批量算好传进来。树里是**一次** `mineOf`
     *      把整棵树涉及的评论 id 查完（见 tree()），不要在这里逐条查。
     *   2. `children` / `has_more` 固定给空值与 false —— 形状必须和树里的节点一致，
     *      前端才能用同一个渲染组件处理"树里的评论"和"刚发出的评论"。
     *      接子级是调用方的事（`assemble()` 负责）。
     *   3. 已删除的评论 `body` 是空串（软删时清过），前端渲染成"该评论已删除"。
     *
     * @param  Comment  $comment  评论
     * @param  array<string, bool>|null  $mine  当前访问者的互动状态（未登录传 null）
     * @return array<string, mixed>  出参
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function toPayload(Comment $comment, ?array $mine = null): array
    {
        $user = $comment->relationLoaded('user') ? $comment->user : null;

        return [
            'id' => (int) $comment->getKey(),
            'parent_id' => $comment->parent_id === null ? null : (int) $comment->parent_id,
            'depth' => (int) $comment->depth,
            'path' => (string) $comment->path,
            'user' => $user === null ? null : [
                // 同 BlogService：`id` 只作兼容，对外用 `public_id`
                'id' => (int) $user->getKey(),
                'public_id' => $user->public_id,
                'name' => (string) $user->name,
                // 头像属 Profile 模块，本期固定 null
                'avatar' => null,
            ],
            'body' => (string) $comment->body,
            'stats' => [
                'like' => (int) $comment->like_count,
                'dislike' => (int) $comment->dislike_count,
                'favorite' => (int) $comment->favorite_count,
                'reply' => (int) $comment->reply_count,
            ],
            'mine' => $mine,
            'created_at' => $comment->created_at?->toIso8601String(),
            'children' => [],
            'has_more' => false,
        ];
    }

    // ------------------------------------------------------------------
    // 内部辅助
    // ------------------------------------------------------------------

    /**
     * 顶层评论分页。
     *
     * @param  string  $targetType  目标类型
     * @param  int  $targetId  目标 ID
     * @param  array{sort?: string|null}  $filters  排序
     * @return LengthAwarePaginator  顶层分页结果
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function paginateTopLevel(string $targetType, int $targetId, array $filters): LengthAwarePaginator
    {
        $query = Comment::query()
            ->with('user:id,name,public_id')
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->whereNull('parent_id');

        if (($filters['sort'] ?? 'new') === 'hot') {
            $query->orderByDesc('like_count')->orderByDesc('created_at');
        } else {
            $query->orderByDesc('created_at');
        }

        return $query->paginate(self::PER_PAGE);
    }

    /**
     * 取这批顶层评论下的全部子孙。
     *
     * 用法：
     *   $rows = $this->loadDescendants('blog', 12, ['0000012/', '0000013/']);
     *
     * 边界/注意：
     *   1. 用 `path LIKE '0000012/%'` —— 每段 7 位零填充，
     *      所以不会把 `0000120/` 误当成 `0000012/` 的子孙（不填充就会串树）。
     *   2. 一次查完按 `path` 排序，拼树时只需顺序遍历一遍。
     *   3. `path` 为空的记录（理论不该有）直接跳过，不让它把拼树逻辑带崩。
     *
     * @param  string  $targetType  目标类型
     * @param  int  $targetId  目标 ID
     * @param  list<string>  $paths  顶层评论的 path 列表
     * @return Collection<int, Comment>  子孙评论（按 path 升序）
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function loadDescendants(string $targetType, int $targetId, array $paths): Collection
    {
        if ($paths === []) {
            return collect();
        }

        return Comment::query()
            ->with('user:id,name,public_id')
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->whereNotNull('parent_id')
            ->where(function ($query) use ($paths): void {
                foreach ($paths as $path) {
                    $query->orWhere('path', 'like', $path.'%');
                }
            })
            ->orderBy('path')
            ->get();
    }

    /**
     * 内存里把扁平的评论列表拼成树（并按深度截断）。
     *
     * 用法：
     *   $items = $this->assemble($tops, $descendants);
     *
     * 边界/注意：
     *   1. 只做**一层**遍历：先把所有行按 `parent_id` 分桶，再从顶层往下接。
     *      不用递归函数 —— 深层嵌套时递归会把调用栈压深，而且写起来更容易错。
     *   2. 超过 `MAX_DISPLAY_DEPTH` 的层级不再展开，打上 `has_more`，
     *      前端据此显示"继续查看"。
     *
     * @param  Collection<int, Comment>  $tops  顶层评论
     * @param  Collection<int, Comment>  $descendants  子孙评论
     * @param  array<int, array<string, bool>>  $mineMap  评论 id => 我的互动状态（批量算好的）
     * @return array<int, array<string, mixed>>  嵌套结构
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function assemble(Collection $tops, Collection $descendants, array $mineMap = []): array
    {
        // parent_id => 它的直接子级（保持 path 升序 = 时间正序的大致顺序）
        $childrenOf = [];
        foreach ($descendants as $row) {
            $childrenOf[(int) $row->parent_id][] = $row;
        }

        $build = function (Comment $comment, int $currentDepth) use (&$build, $childrenOf, $mineMap): array {
            // 节点形状统一走 toPayload，不在这里另拼一份：
            // 两处定义迟早会漂移，而前端是用同一个组件渲染"树里的评论"和"刚发出的评论"的
            $node = $this->toPayload($comment, $mineMap[(int) $comment->getKey()] ?? null);

            $children = $childrenOf[(int) $comment->getKey()] ?? [];

            // 到展示上限就不再往下展开，只标记"还有更多"
            if ($children !== [] && $currentDepth >= Comment::MAX_DISPLAY_DEPTH) {
                $node['has_more'] = true;

                return $node;
            }

            foreach ($children as $child) {
                $node['children'][] = $build($child, $currentDepth + 1);
            }

            return $node;
        };

        $items = [];
        foreach ($tops as $top) {
            $items[] = $build($top, 1);
        }

        return $items;
    }

    /**
     * 校验并取出父评论。
     *
     * @param  int|null  $parentId  父评论 ID（顶层为 null）
     * @param  string  $targetType  目标类型
     * @param  int  $targetId  目标 ID
     * @return Comment|null  父评论，顶层时返回 null
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function resolveParent(?int $parentId, string $targetType, int $targetId): ?Comment
    {
        if ($parentId === null) {
            return null;
        }

        $parent = Comment::query()->find($parentId);

        if ($parent === null) {
            throw new ApiException(ErrorCode::COMMENT_NOT_FOUND);
        }

        if ($parent->target_type !== $targetType || (int) $parent->target_id !== $targetId) {
            throw new ApiException(ErrorCode::COMMENT_PARENT_MISMATCH);
        }

        return $parent;
    }

    /**
     * 增减父评论的直接子回复数。
     *
     * 边界/注意：
     *   减的时候加 `reply_count > 0` 条件：列是无符号整型，减到负数会直接报数据库错误。
     *
     * @param  Comment  $parent  父评论
     * @param  int  $delta  +1 或 -1
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function bumpReplyCount(Comment $parent, int $delta): void
    {
        $query = $parent->newQuery()->whereKey($parent->getKey());

        if ($delta < 0) {
            $query->where('reply_count', '>', 0);
        }

        $query->update(['reply_count' => DB::raw("reply_count + ({$delta})")]);
    }

    /**
     * 增减目标对象上的评论总数。
     *
     * 边界/注意：
     *   本期只处理 `blog`。论坛落地时在这里加一个分支即可 ——
     *   这也说明"目标类型"的知识应该只在这一处，别散到各个 Controller 里。
     *
     * @param  string  $targetType  目标类型
     * @param  int  $targetId  目标 ID
     * @param  int  $delta  +1 或 -1
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    论坛落地时补 `forum_post` 分支
     */
    private function bumpTargetCommentCount(string $targetType, int $targetId, int $delta): void
    {
        if ($targetType !== ReactionService::TARGET_BLOG) {
            return;
        }

        $query = Blog::query()->whereKey($targetId);

        if ($delta < 0) {
            $query->where('comment_count', '>', 0);
        }

        $query->update(['comment_count' => DB::raw("comment_count + ({$delta})")]);
    }

    /**
     * 判断当前用户是不是目标对象的作者。
     *
     * @param  User  $user  当前登录会员
     * @param  Comment  $comment  评论
     * @return bool  是目标对象作者返回 true
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function isTargetOwner(User $user, Comment $comment): bool
    {
        if ($comment->target_type !== ReactionService::TARGET_BLOG) {
            return false;
        }

        $blog = Blog::query()->find($comment->target_id);

        return $blog !== null && (int) $blog->user_id === (int) $user->getKey();
    }
}
