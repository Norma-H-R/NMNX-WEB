<?php

declare(strict_types=1);

/**
 * AdminBlogController —— 后台的博客管理（模块 10 R19）
 *
 * 本文件属于 core（纯 API 后端）的 Blog 模块。
 * 用途：跨会员的博客列表（审核用）与「下架 / 恢复」。
 * 谁在调：`routes/api.php` 里 `/api/v1/admin/blogs*`（挂在 `auth:admin` 组内）。
 *
 * ⚠️ 为什么这个接口当初被标成"暂不在本模块范围内"：
 *    管理端要管什么（只看不删？能不能删？要不要审核队列？）在模块文档里没定死。
 *    现在按**已有依据**实现最小集：
 *      - 列表（跨会员、按状态/关键词筛）
 *      - 下架 / 恢复（`status` 在 published ⇄ hidden 之间切）
 *    **不做删除**：软删是作者自己的动作（member 侧已有），后台去删会
 *    和作者的操作语义打架（作者以为自己的文章还在，实际上被后台删了）。
 *    后台要"让内容消失"就用下架 —— 那是可逆的，而且作者能看到状态。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/10-blog.md
 */

namespace App\Modules\Blog\Http\Controllers;

use App\Modules\Blog\Models\Blog;
use App\Modules\Blog\Services\BlogService;
use App\Modules\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminBlogController
{
    /**
     * 构造：注入博客服务。
     *
     * @param  BlogService  $blogs  博客读写
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function __construct(
        private readonly BlogService $blogs,
    ) {}

    /**
     * 全部博客（跨会员）。
     *
     * 用法：
     *   GET /api/v1/admin/blogs?status=published&keyword=点阵&page=1
     *   200 → data: { "items": [ { …, "status":"published" } ] }
     *         meta: { "page":1, "per_page":20, "total":30 }
     *
     * 边界/注意：
     *   1. 与公开列表最大的区别：**这里全部状态都出**（草稿 / 已发布 / 已下架），
     *      后台要能看到"没人看得到的那部分"。
     *   2. 出参不带 `body_html`（同公开列表的理由：列表带全文会把响应撑大），
     *      但**带 `status`**，列表上要能一眼看出每篇的状态。
     *
     * @param  Request  $request  请求（读 query 上的 status / keyword）
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function index(Request $request): JsonResponse
    {
        $page = $this->blogs->adminList([
            'status' => $request->query('status'),
            'keyword' => $request->query('keyword'),
        ]);

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
     * 下架 / 恢复一篇博客。
     *
     * 用法：
     *   POST /api/v1/admin/blogs/12/hide
     *   { "hidden": true }     // 不传默认 true（下架）
     *   200 → data: { "blog": { …, "status":"hidden" } }
     *
     *   422 → BLOG_COVER_REQUIRED（恢复时这篇一直没有封面）
     *
     * 边界/注意：
     *   **动作可逆**是刻意的设计：后台不是"删掉"，而是"让它暂时不可见"。
     *   这样即使误操作，作者那边也能看到状态并申诉，不会内容凭空消失。
     *   草稿被"下架"没有意义，服务层会把它直接当作"恢复成已发布"处理
     *   （并重新校验封面）—— 详见 BlogService::setHidden 的注释。
     *
     * @param  Request  $request  请求（读 `hidden`）
     * @param  Blog  $blog  路由模型绑定的目标博客
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    接入详情页静态缓存后，在服务层按 slug 失效
     */
    public function hide(Request $request, Blog $blog): JsonResponse
    {
        $updated = $this->blogs->setHidden($blog, $request->boolean('hidden', true));

        return ApiResponse::ok(['blog' => $this->blogs->toPayload($updated)]);
    }
}
