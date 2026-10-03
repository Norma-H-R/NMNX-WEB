<?php

declare(strict_types=1);

/**
 * MemberBlogController —— 会员自己的博客（模块 10）
 *
 * 本文件属于 core（纯 API 后端）的 Blog 模块，按 `docs/modules/10-blog.md` 实现。
 * 用途：我自己的博客列表 / 统计、新建、编辑、删除、发布切换，以及博客互动。
 * 谁在调：`routes/api.php` 里 `/api/v1/member/blogs*`（挂在 `auth:member` 组内）。
 *
 * 这一组**全部需要登录**，所以 `$request->user('member')` 一定是 User。
 * 权限判定（"这篇是不是我的"）不在这里写 —— 统一交给 BlogService，
 * 因为后台管理端将来要判同一件事，判定写两份迟早会漂移。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/10-blog.md
 */

namespace App\Modules\Blog\Http\Controllers;

use App\Models\User;
use App\Modules\Blog\Http\Requests\BlogRequest;
use App\Modules\Blog\Http\Requests\ReactionRequest;
use App\Modules\Blog\Models\Blog;
use App\Modules\Blog\Services\BlogService;
use App\Modules\Blog\Services\ReactionService;
use App\Modules\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberBlogController
{
    /**
     * 构造：注入所需服务。
     *
     * @param  BlogService  $blogs  博客读写
     * @param  ReactionService  $reactions  互动
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function __construct(
        private readonly BlogService $blogs,
        private readonly ReactionService $reactions,
    ) {}

    /**
     * 我的博客列表（**含草稿**）。
     *
     * 用法：
     *   GET /api/v1/member/blogs
     *   200 → data: { "items": [ { …, "status":"draft" } ] }  meta: { page, per_page, total }
     *
     * 边界/注意：
     *   与公开列表的区别就在这里：**公开列表不出草稿，我的列表全出**。
     *   列表条目不返回 `body_html`，理由同公开列表（省流量）。
     *
     * @param  Request  $request  请求
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('member');

        $page = $this->blogs->listMine($user);

        return ApiResponse::ok(
            [
                'items' => $page->getCollection()
                    ->map(fn (Blog $blog): array => $this->blogs->toPayload($blog))
                    ->values()
                    ->all(),
            ],
            [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        );
    }

    /**
     * 我的累计统计。
     *
     * 用法：
     *   GET /api/v1/member/blogs/stats
     *   200 → data: { "stats": { "blog":3, "like":128, "favorite":46, "block":3, "view":2140 } }
     *
     * 边界/注意：
     *   `block`（被拉黑数）**只有作者自己看得到**，所以它只在这个接口出现，
     *   公开列表的 `stats` 里永远不带它 —— 见模块文档决策 7。
     *
     * @param  Request  $request  请求
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function stats(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('member');

        return ApiResponse::ok(['stats' => $this->blogs->statsMine($user)]);
    }

    /**
     * 新建博客（草稿或直接发布）。
     *
     * 用法：
     *   POST /api/v1/member/blogs
     *   { "title":"…", "body_md":"…", "status":"draft", "cover_url":null,
     *     "tags":["性能"], "attachments":[ { "kind":"image", "url":"…", "name":"a.png", "size":1024, "mime":"image/png" } ] }
     *   200 → data: { "blog": { … } }
     *
     *   422 → BLOG_COVER_REQUIRED（status=published 但没封面）
     *
     * 边界/注意：
     *   `slug` **不接受传入**，由后端从标题生成（唯一性也由后端保证）。
     *   前端传了也会被忽略 —— 让前端决定 URL 等于把"重名"和"改链"两个问题都交给它。
     *
     * @param  BlogRequest  $request  已校验的入参
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function store(BlogRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('member');

        $blog = $this->blogs->create($user, $request->toBlogData());

        return ApiResponse::ok(['blog' => $this->blogs->toPayload($blog, withBody: true)]);
    }

    /**
     * 编辑博客（仅作者本人）。
     *
     * 用法：
     *   PUT /api/v1/member/blogs/12
     *   { "title":"新标题", "body_md":"…" }      ← 只传要改的字段
     *   200 → data: { "blog": { … } }
     *
     *   404 → BLOG_NOT_FOUND（不是作者 / 不存在）
     *
     * 边界/注意：
     *   `slug` **改了也不生效**：发布后改 slug 会让旧链接 404，
     *   而详情页是要被搜索引擎收录的（见模块文档决策 5）。
     *
     * @param  BlogRequest  $request  已校验的入参
     * @param  Blog  $blog  路由模型绑定的目标博客
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function update(BlogRequest $request, Blog $blog): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('member');

        $updated = $this->blogs->update($user, $blog, $request->toBlogData());

        return ApiResponse::ok(['blog' => $this->blogs->toPayload($updated, withBody: true)]);
    }

    /**
     * 删除博客（软删）。
     *
     * 用法：
     *   DELETE /api/v1/member/blogs/12
     *   200 → data: {}
     *
     * 边界/注意：
     *   软删而不是物理删：评论和互动都引用它，物理删会留下
     *   一堆指向不存在对象的记录（详情页打开就是空白）。
     *
     * @param  Request  $request  请求
     * @param  Blog  $blog  目标博客
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function destroy(Request $request, Blog $blog): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('member');

        $this->blogs->delete($user, $blog);

        return ApiResponse::ok();
    }

    /**
     * 发布 / 转回草稿。
     *
     * 用法：
     *   POST /api/v1/member/blogs/12/publish
     *   { "publish": true }      // 不传默认 true
     *   200 → data: { "blog": { …, "status":"published" } }
     *
     *   422 → BLOG_COVER_REQUIRED（发布但没封面）
     *
     * 边界/注意：
     *   转草稿**不清空 `published_at`** —— 清空的话，这篇文章再次发布时
     *   在列表里会跳到"最新"，读者会看到一篇早就读过的文章重新出现。
     *
     * @param  Request  $request  请求（读 `publish`）
     * @param  Blog  $blog  目标博客
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function publish(Request $request, Blog $blog): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('member');

        $updated = $this->blogs->togglePublish($user, $blog, $request->boolean('publish', true));

        return ApiResponse::ok(['blog' => $this->blogs->toPayload($updated, withBody: true)]);
    }

    /**
     * 点赞 / 收藏 / 拉黑。
     *
     * 用法：
     *   POST /api/v1/member/blogs/12/reactions
     *   { "type": "like" }        // like | favorite | block
     *   200 → data: { "stats": { like, favorite, block }, "mine": { liked, favorited, blocked } }
     *
     *   403 → BLOG_SELF_REACTION（给自己的博客互动）
     *
     * 边界/注意：
     *   **幂等**：重复点同一个 type 不报错，直接返回当前状态。
     *   前端做了乐观更新，网络抖动导致的重复提交不能变成报错弹窗。
     *
     * @param  ReactionRequest  $request  已校验的入参
     * @param  Blog  $blog  目标博客
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function react(ReactionRequest $request, Blog $blog): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('member');

        $state = $this->reactions->react(
            $user,
            ReactionService::TARGET_BLOG,
            (int) $blog->getKey(),
            (string) $request->string('type'),
        );

        return ApiResponse::ok($state);
    }

    /**
     * 取消某个互动。
     *
     * 用法：
     *   DELETE /api/v1/member/blogs/12/reactions?type=like
     *   200 → data: { "stats": { … }, "mine": { … } }
     *
     * 边界/注意：
     *   `type` 走 **query 参数**而不是请求体：DELETE 带 JSON body 在部分客户端
     *   （老版本 iOS 的某些网络库、部分代理）会被丢掉，这类"偶发不生效"极难排查。
     *   没点过时同样不报错（幂等）。
     *
     * @param  Request  $request  请求（读 query 上的 `type`）
     * @param  Blog  $blog  目标博客
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function unreact(Request $request, Blog $blog): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('member');

        $state = $this->reactions->unreact(
            $user,
            ReactionService::TARGET_BLOG,
            (int) $blog->getKey(),
            (string) $request->query('type', ''),
        );

        return ApiResponse::ok($state);
    }
}
