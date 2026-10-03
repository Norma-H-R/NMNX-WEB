<?php

declare(strict_types=1);

/**
 * CommentController —— 通用评论（模块 10，博客与论坛共用）
 *
 * 本文件属于 core（纯 API 后端）的 Blog 模块，按 `docs/modules/10-blog.md` 实现。
 * 用途：评论树的读、发表、删除、互动。
 * 谁在调：`/api/v1/comments`（**注意：路径里没有 `blog`**）。
 *
 * ⚠️ 路径为什么不写成 `/blogs/{id}/comments`：
 *    评论是**独立系统**，不是博客的附属（模块文档决策 9）。论坛落地时
 *    只需把 `target_type` 换成 `forum_post`，**接口一个都不用新增**。
 *    写成 `/blogs/{id}/comments` 的话，论坛就得再来一套，两套的 bug 还得各修一遍。
 *
 * 鉴权分层（和 `routes/api.php` 里的分组一致）：
 *    读（评论树）        公开 —— 未登录也要能看评论，否则详情页是半残的
 *    写（发表/删除/互动） 需要 `auth:member`
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/10-blog.md
 */

namespace App\Modules\Blog\Http\Controllers;

use App\Models\User;
use App\Modules\Blog\Http\Concerns\ResolvesOptionalMember;
use App\Modules\Blog\Http\Requests\CommentRequest;
use App\Modules\Blog\Http\Requests\ReactionRequest;
use App\Modules\Blog\Models\Comment;
use App\Modules\Blog\Services\CommentService;
use App\Modules\Blog\Services\ReactionService;
use App\Modules\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CommentController
{
    use ResolvesOptionalMember;

    /**
     * 构造：注入所需服务。
     *
     * @param  CommentService  $comments  评论树读写
     * @param  ReactionService  $reactions  互动（评论也自带赞/踩/收藏）
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function __construct(
        private readonly CommentService $comments,
        private readonly ReactionService $reactions,
    ) {}

    /**
     * 评论树（公开）。
     *
     * 用法：
     *   GET /api/v1/comments?target_type=blog&target_id=12&sort=hot&page=1
     *   200 → data: {
     *     "total": 42,
     *     "items": [ { "id":451, "depth":1, "body":"…", "user":{…},
     *                  "stats":{ "like":12, "dislike":0, "favorite":3, "reply":2 },
     *                  "mine":{ "liked":false, … }, "children":[ … ], "has_more":false } ]
     *   }
     *
     * 边界/注意：
     *   1. **顶层分页、子级全带**：读者展开一条评论是想读完整串对话，
     *      中间再插一次分页会把对话截断。
     *   2. 超过 6 层不再展开，节点上带 `has_more: true`，前端显示"继续查看"。
     *   3. 未登录也能读，只是所有 `mine` 为 `null`。
     *
     * @param  Request  $request  请求（读 query 上的 target_* 与 sort）
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function index(Request $request): JsonResponse
    {
        $tree = $this->comments->tree(
            (string) $request->query('target_type', ''),
            (int) $request->query('target_id', 0),
            ['sort' => $request->query('sort')],
            $this->optionalMember($request),
        );

        return ApiResponse::ok($tree);
    }

    /**
     * 发表评论或回复。
     *
     * 用法：
     *   POST /api/v1/comments
     *   { "target_type":"blog", "target_id":12, "parent_id":451, "body":"直接在新机器上激活就行…" }
     *   200 → data: { "comment": { … } }
     *
     *   422 → COMMENT_PARENT_MISMATCH（父评论不属于这个对象）
     *   404 → COMMENT_NOT_FOUND（父评论不存在）
     *
     * 边界/注意：
     *   `parent_id` 省略即为顶层评论。`path` / `depth` 由服务端算，
     *   **不接受传入** —— 算错一条，整棵树的归属就乱了。
     *
     * @param  CommentRequest  $request  已校验的入参
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    接入通知中心（新评论通知目标作者，回复额外通知被回复人）
     */
    public function store(CommentRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('member');

        $comment = $this->comments->create($user, $request->toCommentData());

        return ApiResponse::ok(['comment' => $this->comments->toPayload($comment)]);
    }

    /**
     * 删除评论。
     *
     * 用法：
     *   DELETE /api/v1/comments/451
     *   200 → data: {}
     *
     *   404 → COMMENT_NOT_FOUND（不是评论作者，也不是目标对象作者）
     *
     * 边界/注意：
     *   是**软删 + 清空正文**，子回复会保留在树里（显示成"该评论已删除"）。
     *   物理删掉父评论会让子回复变成找不到爹的孤儿，整棵树在页面上断开。
     *
     * @param  Request  $request  请求
     * @param  Comment  $comment  路由模型绑定的目标评论
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('member');

        $this->comments->delete($user, $comment);

        return ApiResponse::ok();
    }

    /**
     * 评论的赞 / 踩 / 收藏。
     *
     * 用法：
     *   POST /api/v1/comments/451/reactions
     *   { "type": "like" }        // like | dislike | favorite
     *   200 → data: { "stats": { like, dislike, favorite }, "mine": { … } }
     *
     *   403 → BLOG_SELF_REACTION（给自己的评论互动）
     *
     * 边界/注意：
     *   评论的互动类型与博客**不同**（评论有 `dislike` 没有 `block`），
     *   这个差异由 ReactionService 的常量表统一管，控制器不要自己列一遍。
     *
     * @param  ReactionRequest  $request  已校验的入参
     * @param  Comment  $comment  目标评论
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function react(ReactionRequest $request, Comment $comment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('member');

        $state = $this->reactions->react(
            $user,
            ReactionService::TARGET_COMMENT,
            (int) $comment->getKey(),
            (string) $request->string('type'),
        );

        return ApiResponse::ok($state);
    }

    /**
     * 取消评论上的某个互动。
     *
     * 用法：
     *   DELETE /api/v1/comments/451/reactions?type=like
     *   200 → data: { "stats": { … }, "mine": { … } }
     *
     * 边界/注意：
     *   `type` 走 query（同 MemberBlogController::unreact 的理由：
     *   DELETE 带 body 会被部分客户端丢掉，那种"偶发不生效"极难排查）。
     *
     * @param  Request  $request  请求（读 query 上的 `type`）
     * @param  Comment  $comment  目标评论
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function unreact(Request $request, Comment $comment): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('member');

        $state = $this->reactions->unreact(
            $user,
            ReactionService::TARGET_COMMENT,
            (int) $comment->getKey(),
            (string) $request->query('type', ''),
        );

        return ApiResponse::ok($state);
    }
}
