<?php

declare(strict_types=1);

/**
 * rbac:doc —— 把权限目录导出成 Markdown 总表
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块。
 * 用途：把数据库里的权限点与角色基线**生成**成 `docs/permissions.md`，
 *       作为"后期要给每个功能块接权限"时的对照清单。
 * 谁在调：`php artisan rbac:doc`（后台增删改权限之后跑一次即可同步）。
 *
 * 为什么是"生成"而不是手写：
 *   权限点是**可增删改的数据**（后台就能改）。手写的文档必然和库里漂移，
 *   而漂移的清单比没有清单更危险 —— 接权限时会照着错的 key 去写代码。
 *   所以：**数据从库里来，文档由命令产**，任何时候都能一键重建。
 *
 * @version 0.1.0
 * @since   2026-10-03
 * @see     docs/permissions.md
 * @see     docs/permissions-model.md
 */

namespace App\Modules\Rbac\Console;

use App\Modules\Rbac\Services\PermissionRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class GeneratePermissionDocCommand extends Command
{
    /** 命令签名 */
    protected $signature = 'rbac:doc {--check : 只检查文档是否已过期，不写文件}';

    /** 命令说明 */
    protected $description = '把权限目录（分组 + 权限点 + 角色基线矩阵）导出到 docs/permissions.md';

    /**
     * 执行命令。
     *
     * 用法：
     *   php artisan rbac:doc            # 生成 / 覆盖 docs/permissions.md
     *   php artisan rbac:doc --check    # 只比对，不写（给 CI 或提交前自检用）
     *
     * 边界/注意：
     *   数据全部取自 `PermissionRegistry`（即预加载层），所以**不额外查库**，
     *   跑一次就是一次缓存读。
     *
     * @param  \App\Modules\Rbac\Services\PermissionRegistry  $registry  预加载层
     * @return int  退出码（0 成功，1 表示 --check 时发现文档已过期）
     *
     * @version 0.1.0
     * @since   2026-10-03
     * @todo    无
     */
    public function handle(PermissionRegistry $registry): int
    {
        $markdown = $this->render($registry);
        $path = base_path('docs/permissions.md');

        if ($this->option('check')) {
            $current = File::exists($path) ? File::get($path) : '';

            if ($current === $markdown) {
                $this->info('docs/permissions.md 与数据库一致 ✅');

                return self::SUCCESS;
            }

            $this->error('docs/permissions.md 已过期，请跑 php artisan rbac:doc 重新生成');

            return self::FAILURE;
        }

        File::put($path, $markdown);

        $this->info(sprintf(
            '已生成 %s：%d 组 / %d 个权限点 / %d 个角色',
            'docs/permissions.md',
            count($registry->catalog()),
            count($registry->allKeys()),
            count($registry->roles()),
        ));

        return self::SUCCESS;
    }

    /**
     * 渲染 Markdown 全文。
     *
     * @param  \App\Modules\Rbac\Services\PermissionRegistry  $registry  预加载层
     * @return string  Markdown 内容
     *
     * @version 0.1.0
     * @since   2026-10-03
     * @todo    无
     */
    private function render(PermissionRegistry $registry): string
    {
        $catalog = $registry->catalog();
        $roles = $registry->roles();
        $total = count($registry->allKeys());

        $out = "# 权限点总表（{$total} 个 / ".count($catalog)." 组）\n\n";
        $out .= "> ⚠️ **本文件由命令生成，不要手改。**\n";
        $out .= "> 数据在数据库的 `permissions` / `roles` / `role_permissions` 三张表里。\n";
        $out .= "> 后台「权限点管理」里改完，跑一次 `php artisan rbac:doc` 即可同步。\n";
        $out .= "> 落地方式与推进计划见 `docs/permissions-model.md`。\n\n";

        // ── 一、总览 ──────────────────────────────────────────────────────
        $out .= "## 一、总览\n\n| 分组 | 个数 |\n|---|---|\n";

        foreach ($catalog as $group) {
            $out .= '| '.$group['title'].' | '.count($group['items'])." |\n";
        }

        $out .= "| **合计** | **{$total}** |\n\n";

        // ── 二、角色基线 ──────────────────────────────────────────────────
        $out .= "## 二、角色默认基线\n\n";
        $out .= "| 角色 | key | 说明 | 基线权限数 |\n|---|---|---|---|\n";

        foreach ($roles as $role) {
            $count = count($role['permissions']);
            $note = $role['grants_all'] ? '**隐含全部**（不存快照）' : (string) $count;

            $out .= '| '.$role['name'].' | `'.$role['key'].'` | '.$role['desc'].' | '.$note." |\n";
        }

        // ── 三、逐条清单 ──────────────────────────────────────────────────
        $out .= "\n## 三、逐条清单\n";

        foreach ($catalog as $group) {
            $out .= "\n### {$group['title']}（".count($group['items'])."）\n\n";
            $out .= "| 权限标识 | 名称 | 说明 | 来源 |\n|---|---|---|---|\n";

            foreach ($group['items'] as $item) {
                $out .= '| `'.$item['key'].'` | '.$item['label'].' | '.$item['desc'].' | '
                    .($item['custom'] ? '后台新增' : '内置')." |\n";
            }
        }

        // ── 四、权限 × 角色矩阵 ────────────────────────────────────────────
        $out .= "\n## 四、权限 × 角色 矩阵\n\n";
        $out .= "✅ = 该角色的**默认基线**里有它（个人还能另外加减，见 `user_permissions`）。\n\n";

        $header = "| 权限标识 |";
        $divider = '|---|';

        foreach ($roles as $role) {
            $header .= ' '.$role['key'].' |';
            $divider .= '---|';
        }

        $out .= $header."\n".$divider."\n";

        $build = [];

        foreach ($roles as $role) {
            $build[$role['key']] = array_flip($role['permissions']);
        }

        foreach ($catalog as $group) {
            foreach ($group['items'] as $item) {
                $row = '| `'.$item['key'].'` |';

                foreach ($roles as $role) {
                    $row .= isset($build[$role['key']][$item['key']]) ? ' ✅ |' : ' |';
                }

                $out .= $row."\n";
            }
        }

        return $out;
    }
}
