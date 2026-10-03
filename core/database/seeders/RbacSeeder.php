<?php

declare(strict_types=1);

/**
 * RbacSeeder —— 权限目录 + 角色（模块 08）
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块。
 * 用途：把 admin 前端那份契约草稿落库成**权威数据**：
 *         · 权限目录（14 组 / 121 个权限点）
 *         · 5 个内置角色 + 它们的默认权限基线
 * 谁在调：`php artisan db:seed`（经 DatabaseSeeder）、`--class=RbacSeeder`。
 *
 * 数据来源与"谁是权威"：
 *   前 98 个权限点与 5 个角色的基线**逐字来自** `admin/src/mock/permissions.ts`
 *   与 `admin/src/mock/roles.ts`（那是前后端的契约草稿）。落到这里之后，
 *   **数据库是权威**，前端改成从接口读。
 *
 * 幂等规则（重要，别改错）：
 *   · 内置权限点（`is_builtin = true`）：每次 seed 会按**本文件的代码**同步
 *     label / 说明 / 分组 / 排序 —— 代码是权威。
 *   · 界面上新增的权限点（`is_builtin = false`）：seed **完全不碰**。
 *   · 内置角色的**权限基线**：只在**新建角色时**写入。之后重跑 seed **不覆盖** ——
 *     否则后台辛苦调好的基线会被一次 seed 冲掉，那种事故没人喜欢。
 *   · owner：`grants_all = true`，基线**故意留空**（不存快照，避免权限点增删后过期）。
 *
 * 新增的 23 个权限点（原 98 个之外的）来自对 18 个模块的逐项对照，缺的补齐：
 *   反馈(6) / 订单与支付(4) / 统计(3) / 插件(5) / 密钥(3) / 审计补 2
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/08-rbac.md
 */

namespace Database\Seeders;

use App\Modules\Rbac\Enums\RoleTone;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Services\PermissionRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class RbacSeeder extends Seeder
{
    /**
     * 权限目录：分组 → 权限点。
     *
     * 顺序即后台界面上的显示顺序（`group_sort` 取数组下标，`sort` 取组内下标）。
     *
     * ⚠️ 前 9 组（用户管理 → 系统）的 key/label/说明必须与
     *    `admin/src/mock/permissions.ts` **逐字一致**，否则前端页面会认不出来。
     *    第 5、9、11、12、13 组与审计组后两条是本项目补齐的缺口。
     *
     * @var array<int, array{title: string, items: array<int, array{key: string, label: string, desc: string}>}>
     */
    private const PERMISSION_GROUPS = [
        [
            'title' => '用户管理',
            'items' => [
                ['key' => 'user.read', 'label' => '查看用户', 'desc' => '浏览用户列表'],
                ['key' => 'user.detail', 'label' => '查看用户详情', 'desc' => '查看完整资料与业务数据'],
                ['key' => 'user.edit', 'label' => '编辑用户资料', 'desc' => '修改昵称、邮箱、简介等'],
                ['key' => 'user.status', 'label' => '禁用 / 恢复', 'desc' => '冻结或恢复账号'],
                ['key' => 'user.mute', 'label' => '禁言', 'desc' => '限制发言但允许登录'],
                ['key' => 'user.ban', 'label' => '封禁账号', 'desc' => '禁止其登录'],
                ['key' => 'user.role', 'label' => '调整身份', 'desc' => '变更用户身份（博主、版主、管理员）'],
                ['key' => 'user.permission', 'label' => '配置权限', 'desc' => '给管理员逐项分配权限'],
                ['key' => 'user.tag', 'label' => '打标签', 'desc' => '给用户标记分组（大客户、内测等）'],
                ['key' => 'user.note', 'label' => '备注', 'desc' => '添加内部备注，仅后台可见'],
                ['key' => 'user.import', 'label' => '导入用户', 'desc' => '批量导入账号'],
                ['key' => 'user.export', 'label' => '导出用户', 'desc' => '导出用户数据'],
                ['key' => 'user.delete', 'label' => '删除用户', 'desc' => '永久删除账号（不可恢复）'],
            ],
        ],
        [
            'title' => '博客（用户投稿）',
            'items' => [
                ['key' => 'blog.read', 'label' => '查看投稿', 'desc' => '浏览用户投稿'],
                ['key' => 'blog.create', 'label' => '撰写投稿', 'desc' => '新建自己的博客投稿'],
                ['key' => 'blog.review', 'label' => '审核投稿', 'desc' => '通过或驳回待审稿件'],
                ['key' => 'blog.edit', 'label' => '编辑投稿', 'desc' => '修改正文与标题'],
                ['key' => 'blog.publish', 'label' => '发布 / 下架', 'desc' => '控制稿件是否对外可见'],
                ['key' => 'blog.pin', 'label' => '置顶', 'desc' => '在博客列表置顶'],
                ['key' => 'blog.feature', 'label' => '加精', 'desc' => '标记为精选'],
                ['key' => 'blog.category', 'label' => '分类与标签', 'desc' => '维护博客分类与标签'],
                ['key' => 'blog.comment', 'label' => '评论管理', 'desc' => '删除或折叠评论'],
                ['key' => 'blog.delete', 'label' => '删除投稿', 'desc' => '彻底删除稿件'],
            ],
        ],
        [
            'title' => '文章（官方发布）',
            'items' => [
                ['key' => 'article.read', 'label' => '查看文章', 'desc' => '浏览官方文章'],
                ['key' => 'article.create', 'label' => '新建文章', 'desc' => '撰写新文章'],
                ['key' => 'article.edit', 'label' => '编辑文章', 'desc' => '修改正文与排版'],
                ['key' => 'article.preview', 'label' => '预览草稿', 'desc' => '预览未发布的文章'],
                ['key' => 'article.publish', 'label' => '发布 / 撤稿', 'desc' => '控制上线状态'],
                ['key' => 'article.schedule', 'label' => '定时发布', 'desc' => '预约发布时间'],
                ['key' => 'article.category', 'label' => '分类管理', 'desc' => '维护文章分类'],
                ['key' => 'article.revision', 'label' => '版本历史', 'desc' => '查看与回滚历史版本'],
                ['key' => 'article.seo', 'label' => 'SEO 设置', 'desc' => '标题、描述、关键词'],
                ['key' => 'article.delete', 'label' => '删除文章', 'desc' => '彻底删除文章'],
            ],
        ],
        [
            'title' => '公告',
            'items' => [
                ['key' => 'notice.read', 'label' => '查看公告', 'desc' => '浏览公告列表'],
                ['key' => 'notice.create', 'label' => '新建公告', 'desc' => '撰写新公告'],
                ['key' => 'notice.edit', 'label' => '编辑公告', 'desc' => '修改公告内容'],
                ['key' => 'notice.publish', 'label' => '发布 / 下线', 'desc' => '控制是否展示'],
                ['key' => 'notice.push', 'label' => '弹窗推送', 'desc' => '以强提醒方式推送'],
                ['key' => 'notice.target', 'label' => '定向推送', 'desc' => '按身份或标签定向发布'],
                ['key' => 'notice.delete', 'label' => '删除公告', 'desc' => '彻底删除公告'],
            ],
        ],
        [
            'title' => '反馈',
            'items' => [
                ['key' => 'feedback.read', 'label' => '查看反馈', 'desc' => '浏览用户提交的反馈'],
                ['key' => 'feedback.reply', 'label' => '回复反馈', 'desc' => '回复用户的问题'],
                ['key' => 'feedback.handle', 'label' => '处理反馈', 'desc' => '标记为处理中 / 已解决'],
                ['key' => 'feedback.close', 'label' => '关闭反馈', 'desc' => '关闭不再跟进的反馈'],
                ['key' => 'feedback.export', 'label' => '导出反馈', 'desc' => '导出反馈数据'],
                ['key' => 'feedback.delete', 'label' => '删除反馈', 'desc' => '彻底删除反馈'],
            ],
        ],
        [
            'title' => '论坛',
            'items' => [
                ['key' => 'forum.read', 'label' => '查看帖子', 'desc' => '浏览论坛内容'],
                ['key' => 'forum.board.create', 'label' => '创建版块', 'desc' => '新增论坛版块'],
                ['key' => 'forum.board.edit', 'label' => '编辑版块', 'desc' => '修改版块名称与说明'],
                ['key' => 'forum.board.delete', 'label' => '删除版块', 'desc' => '删除版块（需先清空帖子）'],
                ['key' => 'forum.post.create', 'label' => '发帖', 'desc' => '发布新主题'],
                ['key' => 'forum.post.edit', 'label' => '编辑帖子', 'desc' => '修改任意帖子内容'],
                ['key' => 'forum.post.pin', 'label' => '置顶', 'desc' => '在版块内置顶'],
                ['key' => 'forum.post.feature', 'label' => '加精', 'desc' => '标记为精华'],
                ['key' => 'forum.post.highlight', 'label' => '高亮', 'desc' => '标题高亮显示'],
                ['key' => 'forum.post.move', 'label' => '移动帖子', 'desc' => '在版块之间移动'],
                ['key' => 'forum.post.lock', 'label' => '锁定帖子', 'desc' => '禁止继续回复'],
                ['key' => 'forum.post.delete', 'label' => '删除帖子', 'desc' => '删除任意主题'],
                ['key' => 'forum.reply.delete', 'label' => '删除回复', 'desc' => '删除任意楼层'],
                ['key' => 'forum.report', 'label' => '处理举报', 'desc' => '查看并处置举报内容'],
                ['key' => 'forum.user.mute', 'label' => '论坛禁言', 'desc' => '限制某人在论坛发言'],
                ['key' => 'forum.statistics', 'label' => '论坛数据', 'desc' => '查看发帖与活跃统计'],
            ],
        ],
        [
            'title' => '自媒体',
            'items' => [
                ['key' => 'media.read', 'label' => '查看渠道', 'desc' => '浏览渠道与内容'],
                ['key' => 'media.create', 'label' => '新建内容', 'desc' => '创建待发布内容'],
                ['key' => 'media.material', 'label' => '素材库', 'desc' => '管理图片、视频素材'],
                ['key' => 'media.schedule', 'label' => '定时发布', 'desc' => '预约发布时间'],
                ['key' => 'media.publish', 'label' => '发布', 'desc' => '推送到渠道'],
                ['key' => 'media.channel', 'label' => '渠道管理', 'desc' => '绑定 / 解绑渠道账号'],
                ['key' => 'media.comment', 'label' => '评论互动', 'desc' => '回复渠道下的评论'],
                ['key' => 'media.statistics', 'label' => '数据统计', 'desc' => '查看阅读与转化数据'],
                ['key' => 'media.delete', 'label' => '删除内容', 'desc' => '删除已创建内容'],
            ],
        ],
        [
            'title' => '产品与授权',
            'items' => [
                ['key' => 'product.read', 'label' => '查看产品', 'desc' => '浏览产品与版本'],
                ['key' => 'product.create', 'label' => '新建产品', 'desc' => '创建产品'],
                ['key' => 'product.edit', 'label' => '编辑产品', 'desc' => '修改产品信息'],
                ['key' => 'product.version', 'label' => '版本管理', 'desc' => '维护版本号与更新说明'],
                ['key' => 'product.pricing', 'label' => '定价策略', 'desc' => '设置价格与折扣'],
                ['key' => 'product.delete', 'label' => '删除产品', 'desc' => '删除产品（谨慎）'],
                ['key' => 'license.read', 'label' => '查看授权码', 'desc' => '浏览授权码列表'],
                ['key' => 'license.create', 'label' => '生成授权码', 'desc' => '单个生成激活码'],
                ['key' => 'license.batch', 'label' => '批量生成', 'desc' => '按数量批量生成'],
                ['key' => 'license.extend', 'label' => '延期 / 续期', 'desc' => '调整有效期'],
                ['key' => 'license.transfer', 'label' => '转移绑定', 'desc' => '把授权码换绑到另一台机器'],
                ['key' => 'license.revoke', 'label' => '吊销授权', 'desc' => '立即失效某个授权码'],
                ['key' => 'license.blacklist', 'label' => '黑名单', 'desc' => '拉黑机器码或账号'],
                ['key' => 'license.log', 'label' => '校验日志', 'desc' => '查看授权校验记录'],
                ['key' => 'device.read', 'label' => '查看跟随端', 'desc' => '浏览已绑定的机器'],
                ['key' => 'device.unbind', 'label' => '解绑机器', 'desc' => '解除机器与授权码的绑定'],
                ['key' => 'device.command', 'label' => '下发指令', 'desc' => '远程停用或重启跟随端'],
                ['key' => 'device.alarm', 'label' => '告警处理', 'desc' => '处理掉线与异常告警'],
                ['key' => 'device.export', 'label' => '导出机器', 'desc' => '导出机器与绑定数据'],
            ],
        ],
        [
            'title' => '订单与支付',
            'items' => [
                ['key' => 'order.read', 'label' => '查看订单', 'desc' => '浏览会员的购买订单'],
                ['key' => 'order.refund', 'label' => '订单退款', 'desc' => '发起或确认退款'],
                ['key' => 'order.export', 'label' => '导出订单', 'desc' => '导出订单数据对账'],
                ['key' => 'payment.config', 'label' => '支付配置', 'desc' => '配置支付渠道与密钥'],
            ],
        ],
        [
            'title' => '审计',
            'items' => [
                ['key' => 'audit.read', 'label' => '查看日志', 'desc' => '浏览操作与授权日志'],
                ['key' => 'audit.operation', 'label' => '操作日志', 'desc' => '谁在什么时候改了什么'],
                ['key' => 'audit.traffic', 'label' => '访问日志', 'desc' => '访客与会员的访问记录、IP、停留时长'],
                ['key' => 'audit.alert', 'label' => '告警规则', 'desc' => '配置异常行为告警'],
                ['key' => 'audit.report', 'label' => '统计报表', 'desc' => '生成周期报表'],
                ['key' => 'audit.export', 'label' => '导出日志', 'desc' => '导出为文件'],
                ['key' => 'audit.clean', 'label' => '清理日志', 'desc' => '按时间清理历史日志'],
            ],
        ],
        [
            'title' => '统计',
            'items' => [
                ['key' => 'stats.traffic', 'label' => '流量统计', 'desc' => '每日访客与会员的访问量'],
                ['key' => 'stats.page', 'label' => '页面停留', 'desc' => '每个页面/区块的停留时长'],
                ['key' => 'stats.export', 'label' => '导出统计', 'desc' => '导出统计数据'],
            ],
        ],
        [
            'title' => '插件',
            'items' => [
                ['key' => 'plugin.read', 'label' => '查看插件', 'desc' => '浏览已安装插件'],
                ['key' => 'plugin.toggle', 'label' => '启用 / 停用', 'desc' => '开关插件'],
                ['key' => 'plugin.config', 'label' => '插件配置', 'desc' => '修改插件参数'],
                ['key' => 'plugin.install', 'label' => '安装插件', 'desc' => '安装新插件'],
                ['key' => 'plugin.delete', 'label' => '卸载插件', 'desc' => '卸载插件（谨慎）'],
            ],
        ],
        [
            'title' => '密钥',
            'items' => [
                ['key' => 'crypto.key.read', 'label' => '查看密钥', 'desc' => '浏览加密密钥的元信息（不含密钥内容）'],
                ['key' => 'crypto.key.rotate', 'label' => '轮换密钥', 'desc' => '生成新密钥并切换（**最高危**）'],
                ['key' => 'crypto.key.revoke', 'label' => '吊销密钥', 'desc' => '吊销某个密钥版本'],
            ],
        ],
        [
            'title' => '系统',
            'items' => [
                ['key' => 'system.admin', 'label' => '管理员设置', 'desc' => '指定谁是管理员并分配权限'],
                ['key' => 'system.role', 'label' => '身份权限', 'desc' => '配置每种身份的默认权限'],
                ['key' => 'system.param', 'label' => '系统参数', 'desc' => '站点、授权、心跳等参数'],
                ['key' => 'system.notify', 'label' => '通知渠道', 'desc' => '邮件、短信、Webhook 配置'],
                ['key' => 'system.api', 'label' => 'API 密钥', 'desc' => '管理对外接口密钥'],
                ['key' => 'system.webhook', 'label' => 'Webhook', 'desc' => '配置事件回调地址'],
                ['key' => 'system.cache', 'label' => '缓存管理', 'desc' => '刷新 CDN 与缓存'],
                ['key' => 'system.backup', 'label' => '数据备份', 'desc' => '备份与恢复'],
                ['key' => 'system.danger', 'label' => '高危操作', 'desc' => '数据清理、重置等不可逆操作'],
            ],
        ],
    ];

    /**
     * 内置角色 + 默认权限基线。
     *
     * `tone` 的取值见 `RoleTone`；`locked` = 不允许删除；`grants_all` = 隐含全部权限。
     *
     * ⚠️ 基线必须与 `admin/src/mock/roles.ts` 一致（前 5 个角色逐字照抄）。
     *    owner 的基线**故意留空** —— 它隐含全部权限，存快照会在权限点增删后过期。
     *
     * @var array<int, array{key: string, name: string, desc: string, tone: string, locked: bool, grants_all: bool, permissions: array<int, string>}>
     */
    private const ROLES = [
        [
            'key' => 'owner',
            'name' => '超级管理员',
            'desc' => '拥有全部权限，且可以给其它管理员分配权限',
            'tone' => 'gold',
            'locked' => true,
            'grants_all' => true,
            'permissions' => [],
        ],
        [
            'key' => 'admin',
            'name' => '管理员',
            'desc' => '按被分配的权限点管理后台',
            'tone' => 'cyan',
            'locked' => false,
            'grants_all' => false,
            'permissions' => [
                'user.read', 'user.detail', 'user.edit', 'user.status', 'user.mute', 'user.tag', 'user.note',
                'blog.read', 'blog.review',
                'article.read', 'article.create', 'article.edit',
                'notice.read', 'notice.create',
                'forum.read', 'forum.post.delete', 'forum.reply.delete', 'forum.report',
                'audit.read',
            ],
        ],
        [
            'key' => 'moderator',
            'name' => '论坛版主',
            'desc' => '管理论坛的帖子与评论',
            'tone' => 'violet',
            'locked' => false,
            'grants_all' => false,
            'permissions' => [
                'user.read', 'user.detail', 'user.mute', 'user.ban', 'user.note',
                'blog.read',
                'forum.read', 'forum.post.pin', 'forum.post.feature', 'forum.post.highlight',
                'forum.post.move', 'forum.post.lock', 'forum.post.delete',
                'forum.reply.delete', 'forum.report', 'forum.user.mute',
            ],
        ],
        [
            'key' => 'blogger',
            'name' => '博客博主',
            'desc' => '撰写与管理自己的博客投稿',
            'tone' => 'green',
            'locked' => false,
            'grants_all' => false,
            'permissions' => ['blog.read', 'blog.create', 'blog.edit', 'forum.read'],
        ],
        [
            'key' => 'member',
            'name' => '普通用户',
            'desc' => '仅在前台浏览与互动，不进后台',
            'tone' => 'slate',
            'locked' => true,
            'grants_all' => false,
            'permissions' => [],
        ],
    ];

    /**
     * 执行种子。
     *
     * 用法：
     *   php artisan db:seed --class=RbacSeeder
     *
     * 边界/注意：
     *   跑完会 `PermissionRegistry::flush()` 清掉预加载缓存，否则接口还在读旧目录。
     *
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function run(): void
    {
        // 包一个事务：中途失败不会留下"半套权限目录"这种状态
        [$permissionCount, $roleCount] = DB::transaction(fn (): array => [
            $this->seedPermissions(),
            $this->seedRoles(),
        ]);

        // 写完后必须清预加载缓存，否则接口还在读旧目录
        app(PermissionRegistry::class)->flush();

        $this->command?->info(sprintf(
            '权限目录就绪：%d 组 / %d 个权限点；角色 %d 个。',
            count(self::PERMISSION_GROUPS),
            $permissionCount,
            $roleCount,
        ));
    }

    /**
     * 写入权限目录。
     *
     * 边界/注意：
     *   按 `key` 做**批量 upsert**：已存在就按代码同步 label/说明/分组/排序，
     *   不存在就插入。**只动内置项** —— 界面上新增的（is_builtin=false）不在本文件里，
     *   自然不会被碰。
     *
     * @return int 写入的权限点总数
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    private function seedPermissions(): int
    {
        $now = now();
        $rows = [];

        foreach (self::PERMISSION_GROUPS as $groupSort => $group) {
            foreach ($group['items'] as $sort => $item) {
                $rows[] = [
                    'key' => $item['key'],
                    'label' => $item['label'],
                    'description' => $item['desc'],
                    'group_title' => $group['title'],
                    'group_sort' => $groupSort + 1,
                    'sort' => $sort + 1,
                    'is_builtin' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // 一次 upsert 写完 121 条。逐条 updateOrCreate 要 240 次往返，
        // 在 SQLite 上实测 **21.6 秒**；批量之后是一个数量级以内的事。
        Permission::query()->upsert(
            $rows,
            ['key'],
            ['label', 'description', 'group_title', 'group_sort', 'sort', 'is_builtin', 'updated_at'],
        );

        return count($rows);
    }

    /**
     * 写入角色与权限基线。
     *
     * 边界/注意：
     *   1. 已存在的角色**只同步名字/说明/配色**，**不覆盖权限基线** ——
     *      后台调好的基线不该被一次 seed 冲掉。
     *   2. 新角色才写基线。
     *   3. 基线引用的 key 找不到对应权限点时，`sync` 会自动跳过，
     *      但那种情况说明种子里写错了 key（比如拼错），所以要在这里**显式报错**，
     *      不能静默 —— 拼错的 key 会让某个角色少一个权限，线上很难发现。
     *
     * @return int 角色总数
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    private function seedRoles(): int
    {
        $allKeys = Permission::query()->pluck('id', 'key');

        foreach (self::ROLES as $sort => $draft) {
            $missing = array_diff($draft['permissions'], $allKeys->keys()->all());
            if ($missing !== []) {
                throw new \RuntimeException(
                    '角色 ['.$draft['key'].'] 的权限基线里有不存在的权限点：'.implode(', ', $missing),
                );
            }

            $role = Role::query()->firstWhere('key', $draft['key']);

            if ($role === null) {
                $role = Role::query()->create([
                    'key' => $draft['key'],
                    'name' => $draft['name'],
                    'description' => $draft['desc'],
                    'tone' => RoleTone::from($draft['tone']),
                    'is_builtin' => true,
                    'is_locked' => $draft['locked'],
                    'grants_all' => $draft['grants_all'],
                    'sort' => $sort + 1,
                ]);

                $role->permissions()->sync(
                    $allKeys->only($draft['permissions'])->values()->all(),
                );

                continue;
            }

            $role->update([
                'name' => $draft['name'],
                'description' => $draft['desc'],
                'tone' => RoleTone::from($draft['tone']),
                'is_builtin' => true,
                'is_locked' => $draft['locked'],
                'grants_all' => $draft['grants_all'],
                'sort' => $sort + 1,
            ]);
        }

        return Role::query()->count();
    }
}
