<?php

declare(strict_types=1);

/**
 * BlogSeeder —— 博客模块的**模拟数据**（模块 10）
 *
 * 本文件属于 core（纯 API 后端）的 Blog 模块。
 * 用途：往 `blogs` / `blog_attachments` / `reactions` / `comments` 里灌一批像样的假数据，
 *       让列表页、详情页、评论区、我的博客**都能立刻预览**（不用等真实投稿）。
 * 谁在调：`php artisan db:seed --class=BlogSeeder`（开发/演示）。
 *
 * 为什么**不用随机数**：
 *   随机种子每次跑出来都不一样 —— 演示时"昨天那张封面今天没了"，
 *   截图和文档对不上，还容易在调试时误以为是自己改坏了。
 *   这里全部**写死**，同一个库跑多少次结果都一样（幂等：按 slug `updateOrCreate`）。
 *
 * 依赖：会员与角色要先存在（`RbacSeeder` 提供 `blogger` 角色）。
 *       没有作者的博客是没法展示的，所以这里会**自己补几位模拟作者**。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/10-blog.md
 */

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Blog\Models\Blog;
use App\Modules\Blog\Models\BlogAttachment;
use App\Modules\Blog\Models\Comment;
use App\Modules\Blog\Models\Reaction;
use App\Modules\Member\Enums\MemberStatus;
use App\Modules\Rbac\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class BlogSeeder extends Seeder
{
    /** 模拟作者（昵称 => 简介）。**写死**，保证演示可复现 */
    private const AUTHORS = [
        '南门会员' => '在南门星下写点技术笔记。',
        '量化小张' => '专注日内动量策略与执行链路。',
        '点阵工' => '和研究点阵驱动死磕。',
        '前端阿May' => '把交互打磨到不刺眼为止。',
        '老陈运维' => '线上事故写下来就不算白踩。',
    ];

    /** 模拟博客（slug => [标题, 标签, 摘要, 状态]）。正文由摘要扩写而来 */
    private const BLOGS = [
        'dianzhen-yanshi-3ms' => ['点阵延迟 3ms 是怎么测出来的', ['性能', '点阵'], '端到端，从数据落库到点阵出下一帧，每一段都量过。', 'published'],
        'rizhen-momentum-fupan' => ['日内动量策略复盘：滑点吃掉了我一半利润', ['量化', '复盘'], '把成交明细摊开算了一遍，问题比我想的严重。', 'published'],
        'php-module-boundary' => ['PHP 里怎么划模块边界才不返工', ['PHP', '架构'], '一个模块一次做完的节奏，比"先全做完后端"靠谱得多。', 'published'],
        'nuxt-hydration-mismatch' => ['Nuxt 水合不匹配排查记录', ['前端', 'Nuxt'], '随机数写进模板的那一刻，就注定要水合失败。', 'published'],
        'mysql-index-covering' => ['MySQL 覆盖索引实测：快了多少、代价是什么', ['数据库', 'MySQL'], '写放大是真的，读放大也是真的，看你的读写比。', 'published'],
        'vue-keepalive-pitfall' => ['Vue KeepAlive 的 include 为什么没生效', ['前端', 'Vue'], '组件 name 对不上，缓存就是不存在。', 'published'],
        'shenye-jiankong' => ['深夜监控告警：一次磁盘写满的前因后果', ['运维', '事故'], '日志轮转配了，但轮转的目录本身没人清理。', 'published'],
        'comment-tree-design' => ['评论树该用邻接表还是物化路径', ['数据库', '设计'], '深层递归查询是列表页的性能杀手。', 'published'],
        'api-envelope-consistency' => ['接口返回体统一之后，前端少写了多少代码', ['PHP', '规范'], '一个信封，四层守卫，两套人。', 'published'],
        'caozuo-shenpi-gaijian' => ['审批流改成单人确认之后', ['产品', '复盘'], '流程短了，责任反而更清楚。', 'draft'],
        'caogao-weiwancheng' => ['还没写完的草稿：关于"预加载"的一些想法', ['性能', '草稿'], '权限目录每个请求都要读，直接查库肯定慢。', 'draft'],
        'draft-observability' => ['草稿：可观测性到底该从哪一层开始做', ['运维', '草稿'], '先有日志，再谈指标。', 'draft'],
    ];

    /** 模拟评论（blog slug => [父评论索引 or null, 正文]）——按顺序插入，父在前 */
    private const COMMENTS = [
        'dianzhen-yanshi-3ms' => [
            [null, '这个测法很扎实，尤其把渲染和抓帧分开了。'],
            [0, '想问下抓帧是用什么工具？OBS 还是自研探头？'],
            [1, '自研的一个小探头，直接读显存里的帧缓冲。'],
            [0, '3ms 是均值还是 P99？'],
            [3, '均值 3.1ms，P99 大概 6.8ms，抖动主要在切场景时。'],
            [null, '收藏了，正好在排查类似的延迟问题。'],
        ],
        'rizhen-momentum-fupan' => [
            [null, '滑点这块能不能展开讲讲怎么归因的？'],
            [0, '按"下单时刻价差"和"成交偏离"两段拆的，前者占七成。'],
        ],
        'comment-tree-design' => [
            [null, '物化路径在"移动评论"场景下是不是很难受？'],
            [0, '很难受，所以我们这版干脆不支持移动评论。'],
        ],
    ];

    /**
     * 执行种子。
     *
     * 用法：
     *   php artisan db:seed --class=BlogSeeder
     *
     * 边界/注意：
     *   1. **幂等**：按 `slug` 更新，重复跑不会堆出一堆重复博客。
     *   2. `body_html` 用 `Str::markdown()` 生成（服务端渲染的缓存，与规格一致）——
     *      真实写入走 BlogService，这里为了演示直接生成。
     *   3. 计数（`*_count`）由本文件**显式赋值**，因为演示数据没有"真实互动过程"。
     *      生产写入必须走服务，靠"同事务 ±1"维护。
     *
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function run(): void
    {
        $authors = $this->seedAuthors();

        if ($authors === []) {
            $this->command?->error('没有可用作者 —— 请先跑 RbacSeeder（需要 blogger 角色）。');

            return;
        }

        $names = array_keys($authors);
        $blogs = [];

        foreach (self::BLOGS as $slug => [$title, $tags, $excerpt, $status]) {
            // 作者与博客按顺序错开分配，保证每一篇都有作者、且分布不集中
            $authorName = $names[count($blogs) % count($names)];

            $bodyMd = $this->buildBody($title, $excerpt);

            $blog = Blog::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'user_id' => $authors[$authorName]->getKey(),
                    'title' => $title,
                    'excerpt' => $excerpt,
                    'body_md' => $bodyMd,
                    'body_html' => Str::markdown($bodyMd),
                    'cover_url' => $status === Blog::STATUS_PUBLISHED ? $this->coverFor($slug) : null,
                    'tags' => $tags,
                    'status' => $status,
                    'allow_reference' => true,
                    'published_at' => $status === Blog::STATUS_PUBLISHED ? now()->subDays(count($blogs) + 1) : null,
                ],
            );

            // 演示用的冗余计数（真实写入由服务在同一事务里维护）
            $blog->forceFill([
                'view_count' => $status === Blog::STATUS_PUBLISHED ? 120 + count($blogs) * 37 : 3,
                'like_count' => $status === Blog::STATUS_PUBLISHED ? 8 + count($blogs) * 3 : 0,
                'favorite_count' => $status === Blog::STATUS_PUBLISHED ? 3 + count($blogs) : 0,
                'block_count' => count($blogs) % 4,
                'comment_count' => 0,
            ])->save();

            $blogs[$slug] = $blog;
        }

        $this->seedAttachments($blogs);
        $this->seedComments($blogs, $authors);
        $this->seedReactions($blogs, $authors);

        $this->command?->info(sprintf(
            '博客模拟数据就绪：作者 %d 位、博客 %d 篇（含草稿）、评论 %d 条。',
            count($authors),
            count($blogs),
            Comment::query()->count(),
        ));
    }

    /**
     * 补几位模拟作者（挂 blogger 角色）。
     *
     * 用法：
     *   $authors = $this->seedAuthors();   // ['量化小张' => User, …]
     *
     * 边界/注意：
     *   密码是**随机且不落任何地方**的 —— 这些是演示用的作者壳，
     *   不是给人登录的（真实作者从注册接口来）。
     *   昵称已存在就直接复用（幂等）。
     *
     * @return array<string, \App\Models\User>  昵称 => 用户
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function seedAuthors(): array
    {
        $roleId = Role::query()->where('key', 'blogger')->value('id');

        if ($roleId === null) {
            return [];
        }

        $authors = [];

        foreach (self::AUTHORS as $name => $bio) {
            $user = User::query()->firstOrNew(['name' => $name]);

            if (! $user->exists) {
                // ⚠️ 不能用 Str::slug(Str::ascii($name))：**中文会被 ascii 掉成空串**，
                //    五位作者的邮箱于是全变成 `blogger-@nmnx.local`，直接撞唯一键。
                //    用名称的 md5 短串：确定性、中文安全、够唯一。
                $user->email = 'blogger-'.substr(md5($name), 0, 10).'@nmnx.local';
                // 随机密码且不落任何地方：演示用的作者壳，不给人登录
                $user->password = Str::random(40);
            }

            $user->status = MemberStatus::Active;
            $user->role_id = $roleId;
            $user->save();

            $authors[$name] = $user;
        }

        return $authors;
    }

    /**
     * 给前几篇博客挂附件（覆盖 image / pdf / doc / other 四种 kind，方便预览分流展示）。
     *
     * @param  array<string, \App\Modules\Blog\Models\Blog>  $blogs  slug => 博客
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function seedAttachments(array $blogs): void
    {
        $plan = [
            'dianzhen-yanshi-3ms' => [
                ['image/webp', 'latency-chart.webp', 102_400],
                ['application/pdf', 'measure-report.pdf', 524_288],
            ],
            'rizhen-momentum-fupan' => [
                ['image/png', 'slippage.png', 204_800],
                ['text/plain', 'trades.csv', 65_536],
                ['application/x-msdownload', 'backtest-runner.zip', 1_048_576],
            ],
            'nuxt-hydration-mismatch' => [
                ['audio/mpeg', 'repro-notes.mp3', 2_097_152],
            ],
        ];

        foreach ($plan as $slug => $items) {
            if (! isset($blogs[$slug])) {
                continue;
            }

            // 幂等：先清后建（附件没有天然的唯一键，用"数量对得上"判断会误伤）
            $blogs[$slug]->attachments()->delete();

            foreach ($items as $index => [$mime, $name, $size]) {
                BlogAttachment::query()->create([
                    'blog_id' => $blogs[$slug]->getKey(),
                    'kind' => BlogAttachment::kindFromMime($mime),
                    'url' => 'https://cdn.nmnx.local/demo/'.$slug.'/'.$name,
                    'name' => $name,
                    'size' => $size,
                    'mime' => $mime,
                    'sort' => $index,
                ]);
            }
        }
    }

    /**
     * 灌评论树（含多级回复），并回写 `comment_count`。
     *
     * 边界/注意：
     *   `path` / `depth` 必须**先落库拿到 id 再算**（`Comment::buildPath` 依赖 id）。
     *   这里按"父在前"的顺序插入，所以父的 id / path / depth 一定已经就绪。
     *
     * @param  array<string, \App\Modules\Blog\Models\Blog>  $blogs  slug => 博客
     * @param  array<string, \App\Models\User>  $authors  昵称 => 用户
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function seedComments(array $blogs, array $authors): void
    {
        $userIds = array_values(array_map(static fn (User $u): int => $u->getKey(), $authors));

        foreach (self::COMMENTS as $slug => $items) {
            if (! isset($blogs[$slug])) {
                continue;
            }

            $blog = $blogs[$slug];

            // 幂等：这批演示评论每次重建
            Comment::query()->where('target_type', Reaction::TARGET_BLOG)
                ->where('target_id', $blog->getKey())
                ->forceDelete();

            $created = [];

            foreach ($items as $index => [$parentIndex, $body]) {
                $parent = $parentIndex === null ? null : $created[$parentIndex];

                $comment = Comment::query()->create([
                    'target_type' => Reaction::TARGET_BLOG,
                    'target_id' => $blog->getKey(),
                    'user_id' => $userIds[$index % count($userIds)],
                    'parent_id' => $parent?->getKey(),
                    'body' => $body,
                ]);

                // path / depth 依赖自己的 id，只能落库后再算
                $comment->path = Comment::buildPath($parent?->path, $comment->getKey());
                $comment->depth = ($parent?->depth ?? -1) + 1;
                $comment->save();

                $created[] = $comment;

                if ($parent !== null) {
                    $parent->increment('reply_count');
                }
            }

            $blog->forceFill(['comment_count' => count($created)])->save();
        }
    }

    /**
     * 灌互动（点赞 / 收藏 / 拉黑），让列表页的按钮有"已点亮"的样本。
     *
     * @param  array<string, \App\Modules\Blog\Models\Blog>  $blogs  slug => 博客
     * @param  array<string, \App\Models\User>  $authors  昵称 => 用户
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function seedReactions(array $blogs, array $authors): void
    {
        $userIds = array_values(array_map(static fn (User $u): int => $u->getKey(), $authors));

        $index = 0;

        foreach ($blogs as $blog) {
            if (! $blog->isPublished()) {
                continue;
            }

            // 每篇让 1~3 个人点赞、第一个人收藏，制造"已互动"的样本
            $likers = [$userIds[$index % count($userIds)], $userIds[($index + 1) % count($userIds)]];
            $types = [
                [$likers[0], 'like'],
                [$likers[1], 'like'],
                [$userIds[($index + 2) % count($userIds)], 'favorite'],
            ];

            foreach ($types as [$userId, $type]) {
                Reaction::query()->updateOrCreate(
                    [
                        'target_type' => Reaction::TARGET_BLOG,
                        'target_id' => $blog->getKey(),
                        'user_id' => $userId,
                        'type' => $type,
                    ],
                    [],
                );
            }

            $index++;
        }
    }

    /**
     * 生成一篇像样的 Markdown 正文（有标题层级、列表、代码块，方便预览渲染效果）。
     *
     * @param  string  $title  标题
     * @param  string  $excerpt  摘要（作为开篇段）
     * @return string  Markdown 源
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function buildBody(string $title, string $excerpt): string
    {
        return <<<MD
        {$excerpt}

        ## 背景

        这件事最早是从一个"看起来只是慢一点"的现象开始的。量了一遍才发现，
        真正的瓶颈不在我们以为的那一层。

        ## 怎么定位的

        1. 先把整条链路切成段，每段单独计时
        2. 找出耗时占比最大的那一段，先不管其它的
        3. 改完再量一次 —— **不量就等于没改**

        ```php
        // 示意：把耗时打进日志，而不是靠猜
        \$start = microtime(true);
        \$result = \$service->run();
        logger()->info('elapsed', ['ms' => (microtime(true) - \$start) * 1000]);
        ```

        ## 结论

        - 先量再改，改完再量
        - 能一次做完的事不要拆成三次
        - 文档写清楚"为什么这么做"，比写"做了什么"有用

        > 以上是演示数据，由 `BlogSeeder` 生成，用于预览渲染效果。
        MD;
    }

    /**
     * 给一篇模拟博客生成封面地址（演示用固定图，不依赖外网）。
     *
     * @param  string  $slug  博客标识
     * @return string  封面地址
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function coverFor(string $slug): string
    {
        return 'https://cdn.nmnx.local/demo/covers/'.$slug.'.webp';
    }
}
