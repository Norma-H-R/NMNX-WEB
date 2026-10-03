<?php

declare(strict_types=1);

/**
 * PublicBlogController —— 博客的**公开读**接口（无需登录）
 *
 * 本文件属于 core（纯 API 后端）的 Blog 模块。
 * 用途：给官网 `face` 的 `/blog`、`/blog/{slug}` 供数 —— **从数据库向外调**，
 *       替代 `face/app/composables/useBlog.ts` 里那份前端假数据。
 * 谁在调：`routes/api.php` 的 `/api/v1/public/blogs*` 三条路由。
 *
 * 只做 HTTP 翻译：参数校验与查询在控制器里（只读、无写操作，走薄薄一层就够；
 * 有业务规则的写操作才需要 Service）。
 *
 * ⚠️ **出口字段用 snake_case**（`published_at` / `view_count`），与规格一致；
 *    前端那套 camelCase 由它的数据层映射。库里混两套命名半年后没人分得清哪个是真的。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/10-blog.md
 */

namespace App\Modules\Blog\Http\Controllers;

use App\Models\User;
use App\Modules\Blog\Models\Blog;
use App\Modules\Blog\Models\Reaction;
use App\Modules\Support\ErrorCode;
use App\Modules\Support\Exceptions\ApiException;
use App\Modules\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicBlogController
{
    /** 列表每页条数上限（防止有人 ?per_page=100000 把库拖死） */
    private const MAX_PER_PAGE = 50;

    /** 相关推荐条数 */
    private const RELATED_LIMIT = 4;

    /**
     * 博客列表。
     *
     * 用法：
     *   GET /api/v1/public/blogs?sort=latest&tag=性能&q=点阵&page=1&per_page=20
     *
     *   200 → data: { total, page, per_page, items: [ …blog… ] }
     *
     * 边界/注意：
     *   1. **只出已发布的** —— 草稿和官方下架的对公众不可见。
     *   2. `sort=hot` 按 `view_count + like_count*3 + comment_count*5` 排（互动比浏览"贵"，
     *      所以权重更高）。互动数为 0 时不会把新帖埋到底，权重是刻意的。
     *   3. `per_page` 有上限（见 `MAX_PER_PAGE`）：不限的话一个请求就能让库跪。
     *   4. 列表**不带 `body_html`**（省流量）—— 详情才带。
     *
     * @param  \Illuminate\Http\Request  $request  请求（Query：sort / tag / q / page / per_page）
     * @return \Illuminate\Http\JsonResponse  统一响应体
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->integer('per_page', 20)));
        $sort = $request->string('sort')->toString() ?: 'latest';
        $tag = $request->string('tag')->toString();
        $keyword = trim($request->string('q')->toString());

        $query = Blog::query()
            ->with(['author:id,name,public_id', 'attachments'])
            ->where('status', Blog::STATUS_PUBLISHED);

        if ($tag !== '') {
            // tags 是 JSON 列：用 LIKE 命中字符串片段在两种引擎上都可用，
            // 比 JSON_CONTAINS（MySQL 专有）更安全（SQLite 那边没有它）
            $query->where('tags', 'like', '%"'.$tag.'"%');
        }

        if ($keyword !== '') {
            $query->where(function ($inner) use ($keyword): void {
                $inner->where('title', 'like', '%'.$keyword.'%')
                    ->orWhere('excerpt', 'like', '%'.$keyword.'%');
            });
        }

        if ($sort === 'hot') {
            $query->orderByRaw('(view_count + like_count * 3 + comment_count * 5) desc');
        } else {
            $query->orderByDesc('published_at');
        }

        $total = (clone $query)->count();
        $blogs = $query->forPage((int) $request->integer('page', 1), $perPage)->get();

        return ApiResponse::ok([
            'total' => $total,
            'page' => (int) $request->integer('page', 1),
            'per_page' => $perPage,
            'items' => $blogs->map(fn (Blog $blog): array => $this->present($blog, false, $request))->all(),
        ]);
    }

    /**
     * 博客详情。
     *
     * 用法：
     *   GET /api/v1/public/blogs/dianzhen-yanshi-3ms
     *
     *   200 → data: { …blog…, body_html: "<p>…</p>", atts: [ {kind,url,name,size} ] }
     *
     * 边界/注意：
     *   1. 详情**带 `body_html`**（服务端渲染好的），前端直接展示，
     *      **不要让前端自己渲染 Markdown**（那是把 XSS 风险搬到了客户端）。
     *   2. 带会员令牌时会回显 `mine`（我点过赞没）；未登录为 `null`。
     *   3. 草稿**只有作者本人**能通过这个接口看到（别人 404，不泄漏"存在但你看不到"）。
     *
     * @param  \Illuminate\Http\Request  $request  请求
     * @param  string  $slug  博客标识
     * @return \Illuminate\Http\JsonResponse  统一响应体
     *
     * @throws \App\Modules\Support\Exceptions\ApiException  找不到（404）
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $blog = Blog::query()
            ->with(['author:id,name,public_id', 'attachments'])
            ->where('slug', $slug)
            ->first();

        $viewer = $request->user('member');

        if ($blog === null) {
            throw new ApiException(ErrorCode::SYS_NOT_FOUND);
        }

        // 非已发布：只有作者自己能看（而且这里不额外提示"这是草稿"，避免泄漏）
        if (! $blog->isPublished() && ($viewer === null || $viewer->getKey() !== $blog->user_id)) {
            throw new ApiException(ErrorCode::SYS_NOT_FOUND);
        }

        // 阅读数 +1（详情页才加，列表不加 —— 列表里每张卡片都 +1 会让数字失真）
        $blog->increment('view_count');

        return ApiResponse::ok($this->present($blog, true, $request));
    }

    /**
     * 相关推荐（同标签，取 N 条）。
     *
     * 用法：
     *   GET /api/v1/public/blogs/dianzhen-yanshi-3ms/related
     *   200 → data: { items: [ …blog… ] }
     *
     * 边界/注意：
     *   "同标签"用第一个标签匹配（标签权重相同，取第一个就够了）；
     *   没有标签时返回**空列表**而不是报错 —— 相关推荐是锦上添花，不该让详情页挂掉。
     *
     * @param  string  $slug  博客标识
     * @return \Illuminate\Http\JsonResponse  统一响应体
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function related(string $slug): JsonResponse
    {
        $blog = Blog::query()->where('slug', $slug)->first();

        $tags = $blog?->tags ?? [];
        $firstTag = is_array($tags) ? ($tags[0] ?? null) : null;

        if ($firstTag === null || $blog === null) {
            return ApiResponse::ok(['items' => []]);
        }

        $items = Blog::query()
            ->with(['author:id,name,public_id'])
            ->where('status', Blog::STATUS_PUBLISHED)
            ->whereKeyNot($blog->getKey())
            ->where('tags', 'like', '%"'.$firstTag.'"%')
            ->orderByDesc('published_at')
            ->limit(self::RELATED_LIMIT)
            ->get();

        return ApiResponse::ok([
            'items' => $items->map(fn (Blog $item): array => $this->present($item, false, null))->all(),
        ]);
    }

    /**
     * 把一条博客整理成对外结构。
     *
     * 用法：
     *   $this->present($blog, true, $request);   // 详情：带 body_html
     *
     * 边界/注意：
     *   1. **字段名与规格逐字对齐**（`stats.like` / `mine.liked` / `atts`…），因为前端
     *      `useBlog.ts` 就是照这个写的 —— 改名字会让前端整块崩。
     *   2. `mine` 只在**带会员令牌**时返回，未登录为 `null`（前端据此决定按不按亮）。
     *   3. `body_html` 只在 `$withBody` 为真时带上（列表不带，省流量）。
     *
     * @param  \App\Modules\Blog\Models\Blog  $blog  博客
     * @param  bool  $withBody  是否带上正文 HTML（详情传 true）
     * @param  \Illuminate\Http\Request|null  $request  请求（用来判断"我"是谁；为 null 则 mine 也是 null）
     * @return array<string, mixed>  对外结构
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function present(Blog $blog, bool $withBody, ?Request $request): array
    {
        $viewer = $request?->user('member');

        $data = [
            'id' => $blog->getKey(),
            'slug' => $blog->slug,
            'title' => $blog->title,
            'excerpt' => $blog->excerpt,
            'cover' => $blog->cover_url,
            'author' => [
                'id' => $blog->author?->getKey(),
                'name' => $blog->author?->name,
            ],
            'tags' => $blog->tags ?? [],
            'stats' => [
                'like' => $blog->like_count,
                'favorite' => $blog->favorite_count,
                'block' => $blog->block_count,
                'view' => $blog->view_count,
                'comment' => $blog->comment_count,
            ],
            'mine' => $viewer === null ? null : $this->mineFor($blog, $viewer),
            'status' => $blog->status,
            'published_at' => $blog->published_at?->toIso8601String(),
            'updated_at' => $blog->updated_at?->toIso8601String(),
        ];

        if ($withBody) {
            $data['body_html'] = $blog->body_html ?? '';
            $data['atts'] = $blog->attachments
                ->map(static fn ($att): array => [
                    'id' => (string) $att->getKey(),
                    'kind' => $att->kind,
                    'url' => $att->url,
                    'name' => $att->name,
                    'size' => $att->size,
                ])
                ->all();
        }

        return $data;
    }

    /**
     * 算"我"对这条博客的互动状态（点赞/收藏/拉黑各点过没）。
     *
     * @param  \App\Modules\Blog\Models\Blog  $blog  博客
     * @param  \App\Models\User  $viewer  当前会员
     * @return array<string, bool>  互动状态
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    列表页会对 N 条博客各查一次（N+1）。条数上到 50 时要改成"一次查回全部"，
     *          现在 per_page 上限 50，先保正确
     */
    private function mineFor(Blog $blog, User $viewer): array
    {
        $types = Reaction::query()
            ->where('target_type', Reaction::TARGET_BLOG)
            ->where('target_id', $blog->getKey())
            ->where('user_id', $viewer->getKey())
            ->pluck('type')
            ->all();

        return [
            'liked' => in_array('like', $types, true),
            'favorited' => in_array('favorite', $types, true),
            'blocked' => in_array('block', $types, true),
        ];
    }
}
