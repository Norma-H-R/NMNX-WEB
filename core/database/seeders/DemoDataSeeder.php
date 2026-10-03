<?php

declare(strict_types=1);

/**
 * DemoDataSeeder —— 大批量**演示假数据**（论坛 + 产品 + 博客补量）
 *
 * 本文件属于 core（纯 API 后端）。
 * 用途：把论坛、产品、博客三块都填到"能测分页、能看列表撑不撑得住"的量：
 *         论坛  5 个版块 + **45 帖**（含置顶）+ 评论 + 互动
 *         产品  10 个真实产品 + **30 个补充** = 40 个
 *         博客  在 `BlogSeeder` 的 12 篇基础上**补到 40 篇** + 更多评论
 * 谁在调：`php artisan db:seed --class=DemoDataSeeder`（开发 / 演示 / 压列表渲染）。
 *
 * 为什么**不用随机数**：
 *   随机数据每次跑出来都不一样 —— 演示时"昨天那条帖子今天没了"，
 *   截图和文档对不上，调试时还容易误以为是自己改坏了。
 *   这里全部**由下标确定**（第 n 条的标题/作者/时间都由 n 算出来），幂等可重跑。
 *
 * ⚠️ 这是**假数据**，不是种子。真实环境不要跑它（`DatabaseSeeder` 里没有它）。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/11-forum.md
 * @see     docs/modules/14-product.md
 */

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Blog\Models\Blog;
use App\Modules\Blog\Models\Comment;
use App\Modules\Blog\Models\Reaction;
use App\Modules\Forum\Models\ForumBoard;
use App\Modules\Forum\Models\ForumPost;
use App\Modules\Product\Models\Product;
use App\Modules\Rbac\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class DemoDataSeeder extends Seeder
{
    /** 目标数量：帖子 / 产品 / 博客 */
    private const POST_TARGET = 45;

    private const PRODUCT_TARGET = 40;

    private const BLOG_TARGET = 40;

    /** 论坛版块（与前端 `useForum.ts` 里的 5 个一致） */
    private const BOARDS = [
        ['announce', '公告与更新', '版本发布、维护通知', 200, 0],
        ['help', '使用求助', '装不上、跑不起来、报错都发这里', 30, 1],
        ['share', '经验分享', '踩过的坑与验证过的做法', 150, 2],
        ['strategy', '策略讨论', '思路、参数、复盘', 270, 3],
        ['offtopic', '灌水区', '与技术无关的都放这儿', 320, 4],
    ];

    /** 标题素材（按下标组合，保证确定且看起来像人写的） */
    private const TITLE_PREFIX = ['关于', '记录一下', '求助：', '分享：', '复盘：', '讨论：', '踩坑：', '整理：'];

    private const TITLE_TOPIC = [
        '跟随端断线重连', '回测结果对不上实盘', '点阵驱动的帧率', '滑点归因怎么做',
        '授权码轮换流程', 'MySQL 覆盖索引', '接口返回体统一', '评论树的物化路径',
        '限流参数该设多少', '日志里怎么快速定位', 'Nuxt 水合不匹配', '长连接保活策略',
        '风控闸的触发条件', '报表导出的内存占用', '数据同步的幂等设计',
    ];

    /** 产品补充名（10 个真实产品之外再补 30 个，凑到 40） */
    private const PRODUCT_EXTRA = [
        '参数优化器', '行情回放器', '持仓看板', '成交分析器', '策略沙箱',
        '信号回测台', '资金曲线图', '异常探测器', '委托追踪器', '盘口快照器',
        '指标工厂', '事件日历', '快讯聚合器', '订阅分发器', '权限网关',
        '审计浏览台', '加密工具包', '字段脱敏器', '备份调度器', '迁移助手',
        '环境体检器', '依赖雷达', '性能剖析器', '慢查询看板', '部署流水线',
        '灰度控制器', '开关中心', '埋点收集器', '漏斗分析台', '留存看板',
    ];

    private const PRODUCT_STAGES = ['stable', 'beta', 'planned'];

    /**
     * 执行种子。
     *
     * 用法：
     *   php artisan db:seed --class=DemoDataSeeder
     *
     * 边界/注意：
     *   1. **幂等**：论坛帖按 `slug`、产品与博客按 `slug` 更新，重复跑不会翻倍。
     *   2. 依赖：`RbacSeeder`（角色）+ `BlogSeeder`（博客的 12 篇基础数据 + 5 位作者）。
     *      作者不够用时会**自动补**（见 `authors()`）。
     *   3. 时间全部由下推出来（`now()->subMinutes(n * 37)`），保证"最后回复时间"有真实先后。
     *
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function run(): void
    {
        $authors = $this->authors();

        if ($authors === []) {
            $this->command?->error('没有可用作者 —— 请先跑 RbacSeeder 与 BlogSeeder。');

            return;
        }

        $boards = $this->seedBoards();
        $posts = $this->seedPosts($boards, $authors);
        $products = $this->seedProducts();
        $extraBlogs = $this->seedExtraBlogs($authors);
        $comments = $this->seedComments($posts, $authors);

        $this->command?->info(sprintf(
            '演示数据就绪：版块 %d / 帖子 %d（含置顶）/ 产品 %d / 博客 %d / 帖子评论 %d',
            count($boards),
            count($posts),
            count($products),
            Blog::query()->count() + count($extraBlogs),
            $comments,
        ));
    }

    /**
     * 取可用作者（不够就补到 12 位）。
     *
     * 用法：
     *   $authors = $this->authors();   // [User, User, …]
     *
     * 边界/注意：
     *   优先复用 `BlogSeeder` 建好的 5 位作者；数量不够（帖子 45 条要有作者感）时，
     *   再补几位演示作者。密码随机且不落任何地方 —— 这些是**演示壳**，不给人登录。
     *
     * @return list<\App\Models\User>  作者列表
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function authors(): array
    {
        $roleId = Role::query()->where('key', 'blogger')->value('id');

        if ($roleId === null) {
            return [];
        }

        $names = ['南门会员', '量化小张', '点阵工', '前端阿May', '老陈运维',
            '夜班值守', '回测爱好者', '跑单员小李', '数据搬运工', '半路出家',
            '稳健派老周', '参数党'];

        $authors = [];

        foreach ($names as $index => $name) {
            $user = User::query()->firstOrNew(['name' => $name]);

            if (! $user->exists) {
                $user->email = 'demo-'.$index.'-'.substr(md5($name), 0, 8).'@nmnx.local';
                $user->password = Str::random(40);
            }

            $user->status = \App\Modules\Member\Enums\MemberStatus::Active;
            $user->role_id = $roleId;
            $user->save();

            $authors[] = $user;
        }

        return $authors;
    }

    /**
     * 灌版块（幂等，按 slug）。
     *
     * @return array<string, \App\Modules\Forum\Models\ForumBoard>  slug => 版块
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function seedBoards(): array
    {
        $boards = [];

        foreach (self::BOARDS as [$slug, $name, $desc, $hue, $sort]) {
            $boards[$slug] = ForumBoard::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'description' => $desc, 'hue' => $hue, 'sort' => $sort],
            );
        }

        return $boards;
    }

    /**
     * 灌帖子（45 条，按版块轮转分配；前几条置顶）。
     *
     * 边界/注意：
     *   1. `is_pinned` **不经 fillable**（见 ForumPost 的注释：发帖接口不该能置顶），
     *      所以这里用 `forceFill` 显式赋 —— 种子是可信写入方。
     *   2. `excerpt` 从正文首段截；`tags` 取标题主题词；`last_reply_at` 按下标递减，
     *      保证列表排序有真实先后。
     *   3. 每帖都写一条真实结构的评论（顶层 + 一级回复），让 `comment_count` 有真实来源。
     *
     * @param  array<string, \App\Modules\Forum\Models\ForumBoard>  $boards  版块
     * @param  list<\App\Models\User>  $authors  作者池
     * @return list<\App\Modules\Forum\Models\ForumPost>  帖子
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function seedPosts(array $boards, array $authors): array
    {
        $boardSlugs = array_keys($boards);
        $posts = [];

        for ($index = 1; $index <= self::POST_TARGET; $index++) {
            $boardSlug = $boardSlugs[$index % count($boardSlugs)];
            $board = $boards[$boardSlug];
            $author = $authors[$index % count($authors)];

            $prefix = self::TITLE_PREFIX[$index % count(self::TITLE_PREFIX)];
            $topic = self::TITLE_TOPIC[$index % count(self::TITLE_TOPIC)];
            $title = $prefix.$topic.'（'.$index.'）';
            $slug = 't-'.$boardSlug.'-'.$index;

            $bodyMd = $this->bodyFor($title, $index);

            $post = ForumPost::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'board_id' => $board->getKey(),
                    'user_id' => $author->getKey(),
                    'title' => $title,
                    'excerpt' => mb_substr(strip_tags($bodyMd), 0, 80),
                    'body_md' => $bodyMd,
                    'body_html' => Str::markdown($bodyMd),
                    'tags' => [$topic, $board->name],
                    'status' => ForumPost::STATUS_NORMAL,
                ],
            );

            // 前 6 条置顶（列表排序用），其余按 last_reply_at 倒序
            $post->forceFill([
                'is_pinned' => $index <= 6,
                'view_count' => 30 + $index * 17,
                'like_count' => $index % 13,
                'comment_count' => 0,
                'last_reply_at' => now()->subMinutes($index * 37),
                'created_at' => now()->subMinutes($index * 53 + 120),
            ])->save();

            $posts[] = $post;
        }

        // 版块上的冗余计数：按真实帖子重算一次（别用估算，否则和帖子列表对不上）
        foreach ($boards as $board) {
            $board->forceFill([
                'post_count' => ForumPost::query()->where('board_id', $board->getKey())->count(),
                'reply_count' => 0,
            ])->save();
        }

        return $posts;
    }

    /**
     * 给帖子灌评论（每帖 2~3 条，含一层回复），并回填 `comment_count` / 版块 `reply_count`。
     *
     * @param  list<\App\Modules\Forum\Models\ForumPost>  $posts  帖子
     * @param  list<\App\Models\User>  $authors  作者池
     * @return int  写入的评论数
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function seedComments(array $posts, array $authors): int
    {
        $total = 0;

        foreach ($posts as $position => $post) {
            Comment::query()
                ->where('target_type', Reaction::TARGET_FORUM_POST)
                ->where('target_id', $post->getKey())
                ->forceDelete();

            $count = 2 + ($position % 2);
            $created = [];

            for ($i = 0; $i < $count; $i++) {
                // 第 3 条起挂到第 1 条下面，形成一级回复（让树有层级可看）
                $parent = ($i >= 2 && isset($created[0])) ? $created[0] : null;

                $comment = Comment::query()->create([
                    'target_type' => Reaction::TARGET_FORUM_POST,
                    'target_id' => $post->getKey(),
                    'user_id' => $authors[($position + $i + 1) % count($authors)]->getKey(),
                    'parent_id' => $parent?->getKey(),
                    'body' => $this->commentFor($position, $i),
                ]);

                $comment->path = Comment::buildPath($parent?->path, $comment->getKey());
                $comment->depth = ($parent?->depth ?? -1) + 1;
                $comment->save();

                if ($parent !== null) {
                    $parent->increment('reply_count');
                }

                $created[] = $comment;
                $total++;
            }

            $post->forceFill(['comment_count' => count($created)])->save();
        }

        // 版块回复数
        foreach (ForumBoard::query()->get() as $board) {
            $board->forceFill([
                'reply_count' => Comment::query()
                    ->where('target_type', Reaction::TARGET_FORUM_POST)
                    ->whereIn('target_id', ForumPost::query()->where('board_id', $board->getKey())->pluck('id'))
                    ->count(),
                'last_reply_at' => ForumPost::query()->where('board_id', $board->getKey())->max('last_reply_at'),
            ])->save();
        }

        return $total;
    }

    /**
     * 灌产品（10 个真实 + 30 个补充 = 40）。
     *
     * @return list<\App\Modules\Product\Models\Product>  产品
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function seedProducts(): array
    {
        // 前 10 个与官网 `useProduct.ts` 里的真实产品对齐（名字不能乱改，页面在用）
        $real = [
            ['master-ea', '主控 EA', 'stable', '多品种主控，统一信号与风控'],
            ['follower', '跟随端', 'stable', '订阅主控信号并本地执行'],
            ['backtest', '回测引擎', 'beta', '按 tick 回放，支持自定义滑点模型'],
            ['signal-gateway', '信号网关', 'stable', '多路信号归一化与去抖'],
            ['risk-gate', '风控闸', 'stable', '单笔/单日/回撤三重闸门'],
            ['matrix-board', '点阵看板', 'beta', '实时状态点阵化展示'],
            ['license-center', '授权中心', 'stable', '授权码签发、轮换与回收'],
            ['log-probe', '日志探针', 'stable', '就地采集与结构化'],
            ['data-sync', '数据同步器', 'beta', '行情与持仓的幂等同步'],
            ['report-studio', '报表工坊', 'planned', '自定义报表与定时投递'],
        ];

        $products = [];
        $index = 0;

        foreach ($real as [$slug, $name, $status, $tagline]) {
            $products[] = $this->upsertProduct($slug, $name, $status, $tagline, $index++, '1.'.($index % 9).'.0');
        }

        foreach (self::PRODUCT_EXTRA as $name) {
            $slug = 'demo-'.$index;
            $status = self::PRODUCT_STAGES[$index % count(self::PRODUCT_STAGES)];

            $products[] = $this->upsertProduct(
                $slug,
                $name,
                $status,
                '演示产品：'.$name.'，用于测试列表与分页渲染。',
                $index++,
                '0.'.(($index % 9) + 1).'.'.($index % 5),
            );
        }

        return $products;
    }

    /**
     * 写一个产品（幂等）。
     *
     * @param  string  $slug  标识
     * @param  string  $name  名称
     * @param  string  $status  阶段（stable/beta/planned）
     * @param  string  $tagline  一句话简介
     * @param  int  $index  序号（决定排序与色相）
     * @param  string  $version  版本号
     * @return \App\Modules\Product\Models\Product  产品
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function upsertProduct(string $slug, string $name, string $status, string $tagline, int $index, string $version): Product
    {
        $bodyMd = "## {$name}\n\n{$tagline}\n\n### 能做什么\n\n- 与主控/跟随端协同工作\n- 提供可观测的状态与日志\n- 配置项全部有默认值，开箱可用\n\n> 演示数据，由 `DemoDataSeeder` 生成。";

        return Product::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'version' => $version,
                'tagline' => $tagline,
                'status' => $status,
                'tags' => [$status, '演示'],
                'body_md' => $bodyMd,
                'body_html' => Str::markdown($bodyMd),
                'hue' => ($index * 37) % 360,
                'sort' => $index,
            ],
        );
    }

    /**
     * 给博客**补量**到 40 篇（在 `BlogSeeder` 的 12 篇基础上）。
     *
     * @param  list<\App\Models\User>  $authors  作者池
     * @return list<\App\Modules\Blog\Models\Blog>  新增的博客
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function seedExtraBlogs(array $authors): array
    {
        $existing = Blog::query()->count();
        $extra = [];

        for ($index = $existing + 1; $index <= self::BLOG_TARGET; $index++) {
            $topic = self::TITLE_TOPIC[$index % count(self::TITLE_TOPIC)];
            $title = '测试数据：'.$topic.'（'.$index.'）';
            $bodyMd = $this->bodyFor($title, $index);

            $blog = Blog::query()->updateOrCreate(
                ['slug' => 'demo-blog-'.$index],
                [
                    'user_id' => $authors[$index % count($authors)]->getKey(),
                    'title' => $title,
                    'excerpt' => mb_substr(strip_tags($bodyMd), 0, 80),
                    'body_md' => $bodyMd,
                    'body_html' => Str::markdown($bodyMd),
                    'cover_url' => $index % 3 === 0 ? null : 'https://cdn.nmnx.local/demo/covers/demo-blog-'.$index.'.webp',
                    'tags' => [$topic, '测试'],
                    // 每 4 篇留一篇草稿，保证"草稿/已发布"两种状态都有量
                    'status' => $index % 4 === 0 ? Blog::STATUS_DRAFT : Blog::STATUS_PUBLISHED,
                    'published_at' => $index % 4 === 0 ? null : now()->subHours($index),
                ],
            );

            $blog->forceFill([
                'view_count' => 60 + $index * 11,
                'like_count' => $index % 21,
                'favorite_count' => $index % 7,
                'block_count' => $index % 3,
            ])->save();

            $extra[] = $blog;
        }

        return $extra;
    }

    /**
     * 生成一段像样的 Markdown 正文（论坛帖与补充博客共用）。
     *
     * @param  string  $title  标题
     * @param  int  $index  序号（让每篇内容不同）
     * @return string  Markdown 源
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function bodyFor(string $title, int $index): string
    {
        return <<<MD
        如题：{$title}

        ## 情况

        环境是本地 MySQL 8.4 + PHP 8.4，第 {$index} 次遇到类似的现象：

        - 表现：列表能出数据，但排序偶发不对
        - 排查：先看 SQL 的执行计划，再看索引命中情况
        - 结论：`ORDER BY` 用到的字段不在同一个索引里，MySQL 走了文件排序

        ## 做法

        1. 把这个查询最常用的过滤条件和排序字段**合成一个联合索引**
        2. 让覆盖索引把要查的列也带上，避免回表
        3. 改完用 `EXPLAIN` 对比前后

        ```sql
        -- 示意：过滤 + 排序 + 覆盖，一次到位
        CREATE INDEX idx_list ON forum_posts (board_id, is_pinned, last_reply_at);
        ```

        ## 结论

        - 先看执行计划，不要凭感觉加索引
        - 联合索引的**列顺序**取决于"哪个条件选择性最高"
        - 覆盖索引能省回表，但会放大写入成本，读写比要算清楚

        > 演示数据，由 `DemoDataSeeder` 生成。
        MD;
    }

    /**
     * 生成一条评论正文（按下标确定，保证同一位置内容固定）。
     *
     * @param  int  $postIndex  帖子序号
     * @param  int  $commentIndex  评论序号
     * @return string  评论正文
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    private function commentFor(int $postIndex, int $commentIndex): string
    {
        $phrases = [
            '这个思路我试过，确实有效，但要注意索引的列顺序。',
            '请问你的读写比大概是多少？写入多的话覆盖索引不一定划算。',
            '补充一点：如果数据量还会涨，记得提前分区。',
            '同样的坑我踩过，最后是改成联合索引解决的。',
            '有没有对比过不加索引时的耗时？想看看差距。',
            '感谢分享，正好在排查类似问题。',
        ];

        return $phrases[($postIndex + $commentIndex) % count($phrases)];
    }
}
