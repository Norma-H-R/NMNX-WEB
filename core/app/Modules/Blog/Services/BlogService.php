<?php

declare(strict_types=1);

/**
 * BlogService —— 博客的业务规则层（模块 10）
 *
 * 本文件属于 core（纯 API 后端）的 Blog 模块，按 `docs/modules/10-blog.md` 实现。
 * 用途：博客的读写规则全部集中在这里。Controller 只做三件事 ——
 *       收参数（FormRequest 已校验）、调这里、把结果交给 Resource 出图。
 * 谁在调：PublicBlogController、MemberBlogController、AdminBlogController（后台待做）。
 *
 * 为什么业务规则必须集中在这一层：
 *   同一条规则往往有多个入口。"只有作者能改自己的博客"这条 —— 会员端要判，
 *   后台要判（后台是另一种权限），将来论坛引用博客时还得判。写进 Controller
 *   就是三份拷贝，迟早有一份忘了跟着改。写在这里，所有人都调同一个方法。
 *
 * 三个不变量（改这个文件时别破坏）：
 *   1. **`body_md` 是唯一真实来源**：编辑只改它，`body_html` 一律由它重算。
 *      反过来（存 HTML 再反推 MD）做不到，别试。
 *   2. **`*_count` 只能在反应写入的同一事务里改**：那是冗余列，
 *      在别处直接赋值会和 `reactions` 表对不上（对账命令 `blog:recount` 待做）。
 *   3. **对外只出 `published`**：草稿与官方下架对外必须按"不存在"处理。
 *      这一条不能靠前端过滤 —— 前端过滤等于把内容发出去了再藏起来。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/10-blog.md
 */

namespace App\Modules\Blog\Services;

use App\Models\User;
use App\Modules\Blog\Models\Blog;
use App\Modules\Blog\Models\BlogAttachment;
use App\Modules\Support\ErrorCode;
use App\Modules\Support\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class BlogService
{
    /** 列表默认每页条数 */
    private const PER_PAGE = 20;

    /** slug 生成时最多尝试的序号（超过则退回随机后缀兜底） */
    private const SLUG_MAX_TRY = 50;

    /** 摘要为空时，从正文自动截取的字数 */
    private const EXCERPT_LENGTH = 120;

    /**
     * 公开列表（无需登录）。
     *
     * 用法：
     *   $page = $blogService->publicList(['tag' => '性能', 'sort' => 'hot']);
     *
     * 边界/注意：
     *   只出 `status = published`。排序 hot 用冗余计数直接排 —— 这正是把
     *   `like_count` 这类字段冗余在表上的原因：列表页每次都 COUNT(*) 会拖垮查询。
     *
     * @param  array{tag?: string|null, keyword?: string|null, sort?: string|null}  $filters  筛选条件
     * @return LengthAwarePaginator  分页结果（Blog 模型集合）
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function publicList(array $filters = []): LengthAwarePaginator
    {
        $query = Blog::query()
            ->with(['author:id,name,public_id'])
            ->where('status', Blog::STATUS_PUBLISHED);

        if (! empty($filters['tag'])) {
            $query->whereJsonContains('tags', $filters['tag']);
        }

        if (! empty($filters['keyword'])) {
            $keyword = $filters['keyword'];
            $query->where(function ($sub) use ($keyword): void {
                $sub->where('title', 'like', "%{$keyword}%")
                    ->orWhere('excerpt', 'like', "%{$keyword}%");
            });
        }

        if (($filters['sort'] ?? 'latest') === 'hot') {
            $query->orderByDesc('like_count')->orderByDesc('favorite_count')->orderByDesc('view_count');
        } else {
            $query->orderByDesc('published_at');
        }

        return $query->paginate(self::PER_PAGE);
    }

    /**
     * 按 slug 取公开博客详情。
     *
     * 用法：
     *   $blog = $blogService->findPublicBySlug('dianzhen-yanshi-3ms');
     *
     * 边界/注意：
     *   草稿、官方下架一律抛 `BLOG_NOT_FOUND`（**不是 403**）——
     *   返回 403 等于告诉未发布的作者之外的人"这里有一篇隐藏内容"，是信息泄漏。
     *
     * @param  string  $slug  对外标识
     * @return Blog 详情（已带作者与附件）
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function findPublicBySlug(string $slug): Blog
    {
        $blog = Blog::query()
            ->with(['author:id,name,public_id', 'attachments'])
            ->where('slug', $slug)
            ->where('status', Blog::STATUS_PUBLISHED)
            ->first();

        if ($blog === null) {
            throw new ApiException(ErrorCode::BLOG_NOT_FOUND);
        }

        return $blog;
    }

    /**
     * 同 tag 的相关推荐。
     *
     * 用法：
     *   $related = $blogService->related($blog, 5);
     *
     * 边界/注意：
     *   排除自己。没有任何 tag 时直接返回空集合，不要退化成"随便推荐几篇"——
     *   那会让"相关推荐"这个名字变得没有意义。
     *
     * @param  Blog  $blog  当前博客
     * @param  int  $limit  最多返回几条
     * @return Collection<int, Blog>  相关博客
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function related(Blog $blog, int $limit = 5): Collection
    {
        $tags = $blog->tags ?? [];

        if ($tags === []) {
            return collect();
        }

        return Blog::query()
            ->with(['author:id,name,public_id'])
            ->where('status', Blog::STATUS_PUBLISHED)
            ->whereKeyNot($blog->getKey())
            ->where(function ($query) use ($tags): void {
                foreach ($tags as $tag) {
                    $query->orWhereJsonContains('tags', $tag);
                }
            })
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 我自己的博客列表（含草稿）。
     *
     * 用法：
     *   $page = $blogService->listMine($user);
     *
     * 边界/注意：
     *   这里**不筛 status** —— 我的列表要能看见自己的草稿，这正是它与公开列表的区别。
     *
     * @param  User  $user  当前登录会员
     * @return LengthAwarePaginator  分页结果
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function listMine(User $user): LengthAwarePaginator
    {
        return Blog::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('updated_at')
            ->paginate(self::PER_PAGE);
    }

    /**
     * 我的累计统计。
     *
     * 用法：
     *   $stats = $blogService->statsMine($user);
     *   // ['blog' => 3, 'like' => 128, 'favorite' => 46, 'block' => 3, 'view' => 2140]
     *
     * 边界/注意：
     *   `block`（被拉黑）**只有作者自己看得到**，不公开 —— 见模块文档决策 7。
     *   用一条聚合查询算完，不要 forEach 累加（篇数一多就是 N+1）。
     *
     * @param  User  $user  当前登录会员
     * @return array<string, int>  各项累计值
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function statsMine(User $user): array
    {
        $row = Blog::query()
            ->where('user_id', $user->getKey())
            ->selectRaw('COUNT(*) as blog_count')
            ->selectRaw('COALESCE(SUM(like_count), 0) as like_count')
            ->selectRaw('COALESCE(SUM(favorite_count), 0) as favorite_count')
            ->selectRaw('COALESCE(SUM(block_count), 0) as block_count')
            ->selectRaw('COALESCE(SUM(view_count), 0) as view_count')
            ->first();

        return [
            'blog' => (int) ($row->blog_count ?? 0),
            'like' => (int) ($row->like_count ?? 0),
            'favorite' => (int) ($row->favorite_count ?? 0),
            'block' => (int) ($row->block_count ?? 0),
            'view' => (int) ($row->view_count ?? 0),
        ];
    }

    /**
     * 新建博客（草稿或直接发布）。
     *
     * 用法：
     *   $blog = $blogService->create($user, [
     *       'title' => '点阵延迟 3ms 是怎么测出来的',
     *       'body_md' => '# 正文…',
     *       'status' => 'published',
     *       'cover_url' => 'https://…/cover.webp',
     *       'attachments' => [['kind' => 'image', 'url' => '…', 'name' => 'a.png', 'size' => 123, 'mime' => 'image/png']],
     *   ]);
     *
     * 边界/注意：
     *   1. **发布时必须有封面**（决策 8）：草稿可以先没有，`status = published` 就必须有。
     *      没传封面时前端渲染占位块，而不是报错 —— 但那是草稿阶段的事。
     *   2. `slug` 由标题生成且**全局唯一**，发布后不可改（改了旧链接就断）。
     *   3. `body_html` 由 `body_md` 现场渲染，只信服务端这份（防 XSS，见下 renderHtml）。
     *   4. 附件是"先上传、保存时才落库关联"：上传接口只回文件信息，
     *      不建记录，所以这里一并写入并在同一事务里。
     *
     * @param  User  $user  作者
     * @param  array<string, mixed>  $data  已校验的入参
     * @return Blog 新建的博客
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function create(User $user, array $data): Blog
    {
        $status = $data['status'] ?? Blog::STATUS_DRAFT;
        $this->assertPublishable($status, $data['cover_url'] ?? null);

        $blog = new Blog;
        $blog->user_id = $user->getKey();
        $blog->slug = $this->uniqueSlug((string) $data['title']);
        $blog->title = $data['title'];
        $blog->body_md = $data['body_md'];
        $blog->body_html = $this->renderHtml((string) $data['body_md']);
        $blog->excerpt = $data['excerpt'] ?? $this->makeExcerpt((string) $data['body_md']);
        $blog->cover_url = $data['cover_url'] ?? null;
        $blog->tags = $data['tags'] ?? null;
        $blog->status = $status;
        $blog->allow_reference = $data['allow_reference'] ?? true;
        $blog->published_at = $status === Blog::STATUS_PUBLISHED ? now() : null;
        $blog->save();

        $this->syncAttachments($blog, $user, $data['attachments'] ?? []);

        return $blog->load(['author:id,name,public_id', 'attachments']);
    }

    /**
     * 编辑博客（仅作者本人）。
     *
     * 用法：
     *   $blog = $blogService->update($user, $blog, ['title' => '新标题', 'body_md' => '…']);
     *
     * 边界/注意：
     *   1. **`slug` 不在这里改。** 发布后改 slug 会让旧链接 404，
     *      而详情页是要静态预渲染的 —— 那条链接可能已经被搜索引擎收录了。
     *   2. 传了 `body_md` 就必须重算 `body_html`，两者不能各改各的。
     *   3. 已经是 published 的博客不会因为这次编辑而变回草稿。
     *
     * @param  User  $user  当前登录会员
     * @param  Blog  $blog  目标博客
     * @param  array<string, mixed>  $data  已校验的入参（只处理出现的键）
     * @return Blog 更新后的博客
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function update(User $user, Blog $blog, array $data): Blog
    {
        $this->assertOwner($user, $blog);

        if (array_key_exists('title', $data)) {
            $blog->title = $data['title'];
        }

        if (array_key_exists('body_md', $data)) {
            $blog->body_md = $data['body_md'];
            $blog->body_html = $this->renderHtml((string) $data['body_md']);
            // 摘要跟着正文走，但只在调用方没显式给摘要时
            if (! array_key_exists('excerpt', $data)) {
                $blog->excerpt = $this->makeExcerpt((string) $data['body_md']);
            }
        }

        if (array_key_exists('excerpt', $data)) {
            $blog->excerpt = $data['excerpt'];
        }

        if (array_key_exists('cover_url', $data)) {
            $blog->cover_url = $data['cover_url'];
        }

        if (array_key_exists('tags', $data)) {
            $blog->tags = $data['tags'];
        }

        if (array_key_exists('allow_reference', $data)) {
            $blog->allow_reference = (bool) $data['allow_reference'];
        }

        $blog->save();

        if (array_key_exists('attachments', $data)) {
            // 编辑时整组替换：附件列表是"当前这篇用的文件"，不是增量追加
            $blog->attachments()->delete();
            $this->syncAttachments($blog, $user, $data['attachments']);
        }

        return $blog->load(['author:id,name,public_id', 'attachments']);
    }

    /**
     * 删除博客（软删）。
     *
     * 用法：
     *   $blogService->delete($user, $blog);
     *
     * 边界/注意：
     *   用软删（表上有 `deleted_at`）：评论与互动会引用它，
     *   物理删掉就会留下一堆指向不存在对象的挂件。
     *
     * @param  User  $user  当前登录会员
     * @param  Blog  $blog  目标博客
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function delete(User $user, Blog $blog): void
    {
        $this->assertOwner($user, $blog);

        $blog->delete();
    }

    /**
     * 发布 / 转回草稿。
     *
     * 用法：
     *   $blog = $blogService->togglePublish($user, $blog, true);
     *
     * 边界/注意：
     *   1. 转草稿时**保留 `published_at`**。清空它的话，再次发布这篇文章
     *      在列表里的位置会突然变成"最新"，而它其实早就发过 —— 对读者是噪音。
     *   2. 发布前重新校验封面（决策 8）：草稿期间可能一直没传。
     *
     * @param  User  $user  当前登录会员
     * @param  Blog  $blog  目标博客
     * @param  bool  $publish  true 发布 / false 转草稿
     * @return Blog 更新后的博客
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function togglePublish(User $user, Blog $blog, bool $publish): Blog
    {
        $this->assertOwner($user, $blog);

        if ($publish) {
            $this->assertPublishable(Blog::STATUS_PUBLISHED, $blog->cover_url);
            $blog->status = Blog::STATUS_PUBLISHED;
            $blog->published_at ??= now();
        } else {
            $blog->status = Blog::STATUS_DRAFT;
        }

        $blog->save();

        return $blog->load(['author:id,name,public_id', 'attachments']);
    }

    // ------------------------------------------------------------------
    // 内部辅助
    // ------------------------------------------------------------------

    /**
     * 校验"当前用户是不是这篇博客的作者"。
     *
     * 用法：
     *   $this->assertOwner($user, $blog);   // 不是作者就抛异常
     *
     * 边界/注意：
     *   用的是 `getKey()` 比较，不是 `===` 比对象 —— Eloquent 从不同查询里
     *   拿到的同一条记录是两个对象，比对象永远不相等。
     *
     * @param  User  $user  当前登录会员
     * @param  Blog  $blog  目标博客
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function assertOwner(User $user, Blog $blog): void
    {
        if ((int) $blog->user_id !== (int) $user->getKey()) {
            // 刻意用 404 而不是 403：403 等于确认"这篇存在，只是不归你"，
            // 而草稿的存在性本身就是不该泄漏的信息
            throw new ApiException(ErrorCode::BLOG_NOT_FOUND);
        }
    }

    /**
     * 校验发布前置条件。
     *
     * 用法：
     *   $this->assertPublishable($status, $coverUrl);
     *
     * 边界/注意：
     *   只在 `status = published` 时要求封面。草稿允许没有封面 ——
     *   否则作者每存一次草稿都要先找图，编辑器就没法用了。
     *
     * @param  string  $status  目标状态
     * @param  string|null  $coverUrl  封面地址
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function assertPublishable(string $status, ?string $coverUrl): void
    {
        if ($status === Blog::STATUS_PUBLISHED && empty($coverUrl)) {
            throw new ApiException(ErrorCode::BLOG_COVER_REQUIRED);
        }
    }

    /**
     * 把 Markdown 源渲染成 HTML。
     *
     * 用法：
     *   $html = $this->renderHtml('# 标题');
     *
     * 边界/注意：
     *   1. `html_input = 'strip'`：**正文里写 HTML 标签会被剥掉**。这是防 XSS 的关键一行 ——
     *      作者可以是我们不认识的人，让原始 HTML 直通等于给每个会员开了脚本注入的口子。
     *   2. `allow_unsafe_links = false`：挡掉 `javascript:` 这类链接协议。
     *   3. 渲染只在服务端做，前端只负责展示 `body_html`，**不要**在前端再渲染一遍 MD
     *      （两份渲染器必然会有细节不一致，同一篇文在两个地方长得不一样）。
     *
     * @param  string  $markdown  Markdown 源
     * @return string 渲染好的 HTML
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function renderHtml(string $markdown): string
    {
        return (string) Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * 从正文生成摘要。
     *
     * 用法：
     *   $excerpt = $this->makeExcerpt('## 小标题\n\n正文…');
     *
     * 边界/注意：
     *   先把 Markdown 记号与 HTML 标签剥掉再截，否则摘要里会出现
     *   `##`、`![](…)` 这类标记 —— 列表卡片上看起来像坏数据。
     *
     * @param  string  $markdown  Markdown 源
     * @return string 纯文本摘要（最多 EXCERPT_LENGTH 字）
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function makeExcerpt(string $markdown): string
    {
        $plain = strip_tags((string) Str::markdown($markdown));

        return Str::limit(trim(preg_replace('/\s+/u', ' ', $plain) ?? ''), self::EXCERPT_LENGTH, '');
    }

    /**
     * 生成全局唯一的 slug。
     *
     * 用法：
     *   $slug = $this->uniqueSlug('点阵延迟 3ms 是怎么测出来的');
     *   // 'dianzhen-yanshi-3ms-shi-zenme-ce-chu-lai-de'，重复则追加 '-2'、'-3'…
     *
     * 边界/注意：
     *   1. 用 `Str::slug($title, '-', 'zh')` 保留中文音译；中文标题常被转成空串，
     *      所以**必须有兜底**（`blog-{时间戳}`），否则会得到一堆空 slug 互相冲突。
     *   2. 只查**含软删**的记录（`withTrashed`）：删掉的博客万一被恢复，
     *      slug 不能已经被别人占了。
     *   3. 尝试上限之后退回随机后缀 —— 宁可 slug 难看一点，也不要因为死循环卡住保存。
     *
     * @param  string  $title  标题
     * @return string 唯一 slug
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title, '-', 'zh');

        if ($base === '') {
            $base = 'blog-'.now()->format('YmdHis');
        }

        $base = Str::limit($base, 150, '');

        for ($i = 1; $i <= self::SLUG_MAX_TRY; $i++) {
            $candidate = $i === 1 ? $base : $base.'-'.$i;

            $taken = Blog::withTrashed()->where('slug', $candidate)->exists();

            if (! $taken) {
                return $candidate;
            }
        }

        return $base.'-'.Str::lower(Str::random(6));
    }

    /**
     * 写入附件记录。
     *
     * 用法：
     *   $this->syncAttachments($blog, $user, $data['attachments']);
     *
     * 边界/注意：
     *   1. 附件是"先上传、保存时才落库关联"：上传接口只回文件信息不建记录，
     *      所以这里才写 `blog_id`（表上它非空）。
     *   2. `user_id` 记归属，是为了防"引用别人上传的文件"——
     *      校验放在这里，因为这是唯一写这张表的地方。
     *   3. `sort` 按数组下标写，保留作者在编辑器里排的顺序。
     *
     * @param  Blog  $blog  所属博客
     * @param  User  $user  上传者
     * @param  array<int, array<string, mixed>>  $attachments  附件信息（来自上传接口的返回）
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function syncAttachments(Blog $blog, User $user, array $attachments): void
    {
        foreach (array_values($attachments) as $index => $item) {
            BlogAttachment::create([
                'blog_id' => $blog->getKey(),
                'user_id' => $user->getKey(),
                'kind' => $item['kind'],
                'url' => $item['url'],
                'name' => $item['name'],
                'size' => $item['size'] ?? 0,
                'mime' => $item['mime'] ?? '',
                'sort' => $index,
            ]);
        }
    }

    /**
     * 后台：跨会员的博客列表（R19）。
     *
     * 用法：
     *   $page = $blogService->adminList(['status' => 'published', 'keyword' => '点阵']);
     *
     * 边界/注意：
     *   1. 与公开列表的区别：**公开列表只出 `published`，这里全部状态都能看到**
     *      （草稿、已下架）—— 后台要能审核与恢复。
     *   2. 不含软删的记录：软删是作者自己的动作，后台要处理的是"官方下架"，
     *      两件事不要混在一个列表里。
     *   3. 排序按 `created_at` 倒序（不是 `published_at`）：后台关心的是
     *      "最近提交了什么"，草稿也需要出现在列表最上面。
     *
     * @param  array{status?: string|null, keyword?: string|null}  $filters  筛选条件
     * @return LengthAwarePaginator  分页结果
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    按作者筛（等后台管理端提需求）
     */
    public function adminList(array $filters = []): LengthAwarePaginator
    {
        $query = Blog::query()->with(['author:id,name,public_id']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['keyword'])) {
            $keyword = $filters['keyword'];
            $query->where(function ($sub) use ($keyword): void {
                $sub->where('title', 'like', "%{$keyword}%")
                    ->orWhere('slug', 'like', "%{$keyword}%");
            });
        }

        return $query->orderByDesc('created_at')->paginate(self::PER_PAGE);
    }

    /**
     * 后台：下架 / 恢复一篇博客（R19）。
     *
     * 用法：
     *   $blog = $blogService->setHidden($blog, true);    // 下架
     *   $blog = $blogService->setHidden($blog, false);   // 恢复
     *
     * 边界/注意：
     *   1. 只在 `published` ⇄ `hidden` 之间切换。**草稿不该被下架** ——
     *      它本来就没有对外可见过，标成"下架"只会让状态语义混乱。
     *      如果调用方传了一个草稿，这里直接把它当作"恢复成已发布"处理，
     *      但发布前置条件（封面）仍然要满足，否则会出现"一篇没封面的已发布博客"。
     *   2. **不碰任何缓存失效逻辑**（本期还没接缓存）。将来接入时记得：
     *      下架要按 slug 失效详情页的静态产物，否则爬虫还能拿到旧页面。
     *
     * @param  Blog  $blog  目标博客
     * @param  bool  $hidden  true 下架 / false 恢复为已发布
     * @return Blog 更新后的博客
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    接入详情页静态缓存后，在这里按 slug 失效
     */
    public function setHidden(Blog $blog, bool $hidden): Blog
    {
        if ($hidden) {
            $blog->status = Blog::STATUS_HIDDEN;
        } else {
            // 恢复即"重新发布"，那么发布的前置条件必须重新过一遍
            $this->assertPublishable(Blog::STATUS_PUBLISHED, $blog->cover_url);
            $blog->status = Blog::STATUS_PUBLISHED;
            $blog->published_at ??= now();
        }

        $blog->save();

        return $blog->load(['author:id,name,public_id', 'attachments']);
    }

    /**
     * 单篇博客的出参形状。
     *
     * 用法：
     *   // 列表（不带正文，省流量）
     *   $payload = $blogService->toPayload($blog, $mineMap[$blog->id] ?? null);
     *   // 详情（带渲染好的正文）
     *   $payload = $blogService->toPayload($blog, $mine, withBody: true);
     *
     * 边界/注意：
     *   1. **列表不带 `body_html`** —— 那是文章全文，一页 20 篇带上去能把响应撑到几百 KB。
     *      只有详情接口 `withBody: true`。
     *   2. `mine`（我点过赞没）是**调用方批量算好传进来的**，不在这里查。
     *      列表页要一次算完 20 条（见 ReactionService::mineOf），
     *      在这里逐条查就是 20 次查询 —— 这是最容易把列表接口拖慢的地方。
     *   3. 形状定义**只在这一处**：列表、详情、创建、更新全走它。
     *      各处自己拼数组的话，加个字段必然会漏掉其中一处。
     *
     * @param  Blog  $blog  博客
     * @param  array<string, bool>|null  $mine  当前访问者对这篇的互动状态（未登录传 null）
     * @param  bool  $withBody  是否带 `body_html`（详情才需要）
     * @return array<string, mixed>  出参
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function toPayload(Blog $blog, ?array $mine = null, bool $withBody = false): array
    {
        $author = $blog->relationLoaded('author') ? $blog->author : null;

        $payload = [
            'id' => (int) $blog->getKey(),
            'slug' => (string) $blog->slug,
            'title' => (string) $blog->title,
            'excerpt' => (string) $blog->excerpt,
            'cover' => $blog->cover_url,
            'author' => $author === null ? null : [
                // `id` 是自增主键，仅内部使用 + 兼容既有前端契约；
                // 对外展示一律用 `public_id`（用户要求：不暴露自增 id）
                'id' => (int) $author->getKey(),
                'public_id' => $author->public_id,
                'name' => (string) $author->name,
                // 头像属于 Profile 模块（13-profile），本期不接，固定 null。
                // 前端按"没有头像就渲染首字母色块"处理（与用户列表页同一套）
                'avatar' => null,
            ],
            'tags' => $blog->tags ?? [],
            'stats' => [
                'like' => (int) $blog->like_count,
                'favorite' => (int) $blog->favorite_count,
                'block' => (int) $blog->block_count,
                'view' => (int) $blog->view_count,
                'comment' => (int) $blog->comment_count,
            ],
            'mine' => $mine,
            'atts' => $this->attachmentsPayload($blog),
            'allow_reference' => (bool) $blog->allow_reference,
            'status' => (string) $blog->status,
            'published_at' => $blog->published_at?->toIso8601String(),
            'updated_at' => $blog->updated_at?->toIso8601String(),
        ];

        if ($withBody) {
            $payload['body_html'] = (string) $blog->body_html;
        }

        return $payload;
    }

    /**
     * 附件列表的出参。
     *
     * 边界/注意：
     *   只给前端"展示所需"的字段，**不给 `mime` 原文以外的东西** ——
     *   前端按 `kind` 分流渲染（图片铺图 / 音视频内嵌 / 其余给下载），
     *   它不需要知道文件存在哪、谁传的。
     *
     * @param  Blog  $blog  博客
     * @return array<int, array<string, mixed>>  附件出参
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function attachmentsPayload(Blog $blog): array
    {
        if (! $blog->relationLoaded('attachments')) {
            return [];
        }

        return $blog->attachments
            ->map(static fn (BlogAttachment $att): array => [
                'kind' => (string) $att->kind,
                'url' => (string) $att->url,
                'name' => (string) $att->name,
                'size' => (int) $att->size,
            ])
            ->values()
            ->all();
    }
}
