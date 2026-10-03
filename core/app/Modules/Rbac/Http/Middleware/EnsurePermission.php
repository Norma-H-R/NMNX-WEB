<?php

declare(strict_types=1);

/**
 * EnsurePermission —— 全项目**唯一的鉴权通道**
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块。
 * 用途：路由上写 `->middleware('permission:forum.post.delete')`，
 *       请求进来时统一判一次"这个人能不能做这件事"，不行就 403。
 * 谁在调：`routes/api.php` 里需要权限的路由（别名 `permission`，见 `bootstrap/app.php`）。
 *
 * 为什么要有这么一个中间件、而不是在各个控制器里写 `if (! can(...))`：
 *   1. **一处入口，漏不掉**：散落的判断必然会漏（漏掉的那个接口就是裸奔的，
 *      而且从代码上完全看不出来）；
 *   2. **声明式**：看路由就能知道这个接口要什么权限，不用翻进服务层；
 *   3. **提示统一**：403 的文案格式只有一处，前端只需按一种格式弹窗。
 *
 * ⚠️ 它是**安全边界**，不是"体验优化"。前端按权限 key 隐藏按钮只是不让用户白点，
 *    **真正的拦截在这里** —— 所以伪造前端的权限列表不可能提权。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/permissions-model.md 第 1 节、第 6 节
 */

namespace App\Modules\Rbac\Http\Middleware;

use App\Modules\Rbac\Services\PermissionRegistry;
use App\Modules\Support\ErrorCode;
use App\Modules\Support\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePermission
{
    /**
     * 构造：注入预加载层（判定走它的请求内缓存，不反复查库）。
     *
     * @param  \App\Modules\Rbac\Services\PermissionRegistry  $registry  预加载层
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function __construct(private readonly PermissionRegistry $registry) {}

    /**
     * 处理请求。
     *
     * 用法：
     *   Route::delete('/posts/{post}', ...)->middleware('permission:forum.post.delete');
     *
     * 边界/注意：
     *   1. **主体从守卫里取，绝不从请求体取** —— 请求里带 `role` / `permissions` 一律无视。
     *      这是"不可能提权"的根本原因（有回归测试钉住）。
     *   2. 取主体的顺序：`admin` 守卫 → `member` 守卫 → 默认守卫。
     *      一个中间件同时服务后台与前台，这就是"走同一个通道"。
     *   3. 没登录 → 401（`SYS_UNAUTHENTICATED`），登录了但没权限 → 403（`SYS_FORBIDDEN`）。
     *      两者要区分开：401 该让人重新登录，403 该告诉他"你缺哪个权限"。
     *   4. 403 的文案带上权限点的**中文名**（"你没有「删除帖子」权限"）。
     *      只回"操作失败"会把排查成本转给使用者，那种提示等于没提示。
     *
     * @param  \Illuminate\Http\Request  $request  请求
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next  下一个处理者
     * @param  string  $ability  权限点 key（路由参数，如 `forum.post.delete`）
     * @return \Symfony\Component\HttpFoundation\Response  响应
     *
     * @throws \App\Modules\Support\Exceptions\ApiException  未登录（401）或无权限（403）
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $subject = $request->user('admin') ?? $request->user('member') ?? $request->user();

        if ($subject === null) {
            throw new ApiException(ErrorCode::SYS_UNAUTHENTICATED);
        }

        if (! $this->registry->allows($subject, $ability)) {
            throw new ApiException(
                ErrorCode::SYS_FORBIDDEN,
                '你没有「'.$this->registry->labelOf($ability).'」权限',
            );
        }

        return $next($request);
    }
}
