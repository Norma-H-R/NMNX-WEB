<?php

declare(strict_types=1);

/**
 * RbacServiceProvider —— Rbac 模块的容器绑定
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块。
 * 用途：把 `PermissionRegistry` 注册成**单例**。
 * 谁在调：Laravel 启动时自动加载（见 `bootstrap/providers.php`）。
 *
 * 为什么必须单例（不是"顺手优化"）：
 *   `PermissionRegistry` 的"请求内记忆"存在**实例属性**上。如果每次
 *   `app(PermissionRegistry::class)` 都造一个新对象，那份记忆等于没有 ——
 *   一次请求里鉴权十次就会查十次库。注册成单例，记忆才真正生效。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/08-rbac.md
 */

namespace App\Modules\Rbac;

use App\Modules\Rbac\Console\GeneratePermissionDocCommand;
use App\Modules\Rbac\Services\PermissionRegistry;
use Illuminate\Support\ServiceProvider;

final class RbacServiceProvider extends ServiceProvider
{
    /**
     * 注册容器绑定。
     *
     * 用法：
     *   // 由框架自动调用；业务里不要手动 new PermissionRegistry
     *   app(PermissionRegistry::class);
     *
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function register(): void
    {
        $this->app->singleton(PermissionRegistry::class);
    }

    /**
     * 启动：注册本模块的 artisan 命令。
     *
     * 用法：
     *   php artisan rbac:doc            # 权限目录 → docs/permissions.md
     *   php artisan rbac:doc --check    # 只比对是否已过期（提交前自检）
     *
     * 边界/注意：
     *   模块目录（`app/Modules/...`）不在 Laravel 的自动发现路径（只有 `app/Console/Commands` 会），
     *   所以必须在这里显式注册 —— 少了这一行，命令会"安静地不存在"。
     *
     * @return void
     *
     * @version 0.1.0
     * @since   2026-10-03
     * @todo    无
     */
    public function boot(): void
    {
        $this->commands([
            GeneratePermissionDocCommand::class,
        ]);
    }
}
