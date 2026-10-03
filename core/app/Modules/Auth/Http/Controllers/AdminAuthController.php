<?php

declare(strict_types=1);

/**
 * AdminAuthController —— 后台鉴权的 HTTP 入口
 *
 * 本文件属于 core（纯 API 后端）的 Auth 模块。
 * 用途：**只做 HTTP 翻译** —— 取参 → 交给 Request 校验 → 调 Service → 用 ApiResponse 出参。
 *       不出现 SQL、不出现业务分支（见 docs/standards.md 第 2.4 节）。
 * 谁在调：`routes/api.php` 里 `/api/v1/admin/*` 三条路由。
 *
 * 刻意**不继承** `App\Http\Controllers\Controller`：Laravel 11 之后基类里那些
 * 中间件注册助手已经不再需要，继承一个空基类只是多一层要读的东西。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/01-auth.md
 */

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Requests\AdminLoginRequest;
use App\Modules\Auth\Http\Resources\AdminResource;
use App\Modules\Auth\Models\Admin;
use App\Modules\Auth\Services\AdminAuthService;
use App\Modules\Rbac\Services\PermissionRegistry;
use App\Modules\Support\Exceptions\ApiException;
use App\Modules\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminAuthController
{
    /**
     * 构造：注入业务服务。
     *
     * 用法：
     *   // 由容器自动注入，控制器里不要 new：
     *   return app(AdminAuthController::class)->login($request);
     *
     * 边界/注意：
     *   用构造函数注入而不是 `app(...)` 就地取 —— 测试里能替换成假的 Service。
     *
     * @param  AdminAuthService  $auth  登录 / 登出的业务规则
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function __construct(
        private readonly AdminAuthService $auth,
        private readonly PermissionRegistry $registry,
    ) {}

    /**
     * 登录：账号 + 密码 → 令牌。
     *
     * 用法：
     *   POST /api/v1/admin/login
     *   { "username": "root", "password": "……", "device_name": "chrome" }
     *
     *   200 → data: { "token": "1|xxxx", "admin": { … } }
     *   401 → AUTH_INVALID_CREDENTIALS（账号不存在或密码错，**不区分**）
     *   403 → AUTH_ACCOUNT_DISABLED
     *   422 → SYS_VALIDATION，明细在 meta.errors
     *   429 → SYS_RATE_LIMITED（同账号同 IP 每分钟超过阈值）
     *
     * 边界/注意：
     *   明文令牌**只在这个响应里出现一次**，服务端只存哈希，丢了只能重新登录。
     *   前端要立刻把它落进存储（记住我 → localStorage，否则 sessionStorage）。
     *
     * @param  AdminLoginRequest  $request  已校验的入参
     * @return JsonResponse 统一响应体
     *
     * @throws ApiException 由全局异常处理器转成统一出参
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function login(AdminLoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            username: (string) $request->string('username'),
            password: (string) $request->string('password'),
            deviceName: $request->string('device_name')->toString() ?: null,
            ip: $request->ip(),
        );

        return ApiResponse::ok([
            'token' => $result['token'],
            'admin' => new AdminResource($result['admin']),
            /*
             * 把**他自己那份**权限一起下发（前端拿它控按钮显隐）。
             *
             * 这只是"体验"：真正的拦截在 `permission:` 中间件。
             * 前端就算把这份列表改成"全都有"，请求照样会被 403 —— 服务端从不读客户端的权限声明。
             */
            'permissions' => $this->registry->permissionsOf($result['admin']),
        ]);
    }

    /**
     * 登出：吊销本次请求所用的令牌。
     *
     * 用法：
     *   POST /api/v1/admin/logout      （需带 Bearer 令牌）
     *
     *   200 → data: {}
     *
     * 边界/注意：
     *   只吊销当前设备的令牌，其它设备不受影响。
     *   响应刻意不区分"确实删掉了一个令牌"与"本来就没有令牌"——
     *   两者对外都是"你现在是登出状态"，没必要暴露内部状态。
     *
     * @param  Request  $request  当前请求
     * @return JsonResponse 统一响应体（data 为空对象）
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        $this->auth->logout($admin);

        return ApiResponse::ok();
    }

    /**
     * 当前登录者信息（admin 前端做页面初始化用）。
     *
     * 用法：
     *   GET /api/v1/admin/me           （需带 Bearer 令牌）
     *
     *   200 → data: { "admin": { … } }
     *
     * 边界/注意：
     *   出参**统一包在 `admin` 键里**，与登录接口的 `data.admin` 保持一致 ——
     *   前端两处可以用同一段解析代码（`data.admin`）。
     *
     * @param  Request  $request  当前请求
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    补 `abilities`（权限标识列表），等模块 08 Rbac 落地
     */
    public function me(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        return ApiResponse::ok([
            'admin' => new AdminResource($admin),
            'permissions' => $this->registry->permissionsOf($admin),
        ]);
    }

    /**
     * 只取"我自己的权限列表"。
     *
     * 用法：
     *   GET /api/v1/admin/me/permissions
     *   200 → data: { "permissions": ["user.read", "article.create", …] }
     *
     * 为什么单独开一个接口：
     *   前端**刷新页面**时只有令牌、没有权限列表（那份是登录响应给的）。
     *   而且用户权限可能在任何时候被管理员改掉 ——
     *   前端收到 **403 之后要能重新拉一次**，把已经没权限的入口收起来。
     *   走 `/me` 也行，但这个接口更轻，且语义就是"刷新我的权限"。
     *
     * 边界/注意：
     *   **只能取自己的**：不接受任何"查某人的权限"参数 —— 从接口设计上杜绝越权查询。
     *
     * @param  \Illuminate\Http\Request  $request  请求（取当前登录管理员）
     * @return \Illuminate\Http\JsonResponse  统一响应体
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function permissions(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');

        return ApiResponse::ok([
            'permissions' => $this->registry->permissionsOf($admin),
        ]);
    }
}
