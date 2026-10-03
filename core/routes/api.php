<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\AdminAuthController;
use App\Modules\Blog\Http\Controllers\AdminBlogController;
use App\Modules\Blog\Http\Controllers\CommentController;
use App\Modules\Blog\Http\Controllers\MemberBlogController;
use App\Modules\Blog\Http\Controllers\PublicBlogController;
use App\Modules\Member\Http\Controllers\MemberAuthController;
use App\Modules\Rbac\Http\Controllers\PermissionController;
use App\Modules\Rbac\Http\Controllers\RoleController;
use App\Modules\Support\Http\ApiResponse;
use Illuminate\Support\Facades\Route;

/**
 * API 路由
 *
 * 本文件定义 core（纯 API 后端）的全部对外入口边界。
 *
 * 四层守卫 —— 以后加接口一律往对应组里加，**不要在别处另开入口**：
 *
 *   /api/v1/public   任何人（含爬虫）读公开数据，无鉴权
 *   /api/v1/member   已登录的前台会员（**`auth:member`**，绑定 users 总表）
 *   /api/v1/admin    后台管理者（**`auth:admin`**，绑定 admins 表）
 *   /api/v1/client   EA 客户端，签名 + 授权码 + 幂等
 *
 * ⚠️ 除了 public，每一层都必须写**带 provider 的守卫**（`auth:member` / `auth:admin`），
 *    不能写 `auth:sanctum` —— 后者的 provider 是 null，会放行任何令牌（越权）。
 *
 * 出参规范：所有响应必须经 ApiResponse 构造（见 docs/standards.md 第 2.4 节）。
 *
 * @version 0.4.0
 *
 * @since   2026-10-03
 * @see     docs/README.md
 */
Route::prefix('v1')->group(function () {

    /*
    | 探针：确认 api 层挂载正常、时区与版本正确。
    | 出参里的 server_time 是给客户端对时用的（EA 侧不允许信任本机时钟）。
    */
    Route::get('/health', fn () => ApiResponse::ok([
        'service' => 'nmnx-core',
        'api' => 'v1',
        'server_time' => now()->toIso8601String(),
    ]));

    /*
    | 官网：公开只读，无鉴权。
    | 公开内容**必须**保持无鉴权 —— 官网是 SSR + SEO 的，加鉴权爬虫就拿不到数据。
    */
    Route::prefix('public')->group(function () {
        Route::get('/ping', fn () => ApiResponse::ok(['guard' => 'public']));

        /*
        | 博客公开读（模块 10 R12）。
        |
        | 详情用 **slug** 而不是自增 id：详情页要做静态预渲染，可读的 URL 才有意义
        | （`/blogs/dianzhen-yanshi-3ms` 而不是 `/blogs/12`）。
        | `related` 要放在 `{slug}` **之后**没问题 —— 路径段数不同，不会打架。
        */
        Route::get('/blogs', [PublicBlogController::class, 'index']);
        Route::get('/blogs/{slug}', [PublicBlogController::class, 'show']);
        Route::get('/blogs/{slug}/related', [PublicBlogController::class, 'related']);
    });

    /*
    | 通用评论（模块 10）。
    |
    | ⚠️ 路径里**没有 `blog`** —— 评论是独立系统（模块文档决策 9），
    |    论坛落地时只需换一个 `target_type`，接口一个都不用新增。
    | 读公开（详情页的评论也要能被爬虫看到），写要登录。
    */
    Route::get('/comments', [CommentController::class, 'index']);

    Route::middleware('auth:member')->group(function () {
        Route::post('/comments', [CommentController::class, 'store']);
        Route::delete('/comments/{comment}', [CommentController::class, 'destroy']);
        Route::post('/comments/{comment}/reactions', [CommentController::class, 'react']);
        Route::delete('/comments/{comment}/reactions', [CommentController::class, 'unreact']);
    });

    /*
    | 会员端。
    |
    | 守卫是 **member**（provider = users 总表），与管理者的 admins 表分开。
    | 注册 / 登录这两个接口本身**不挂守卫**（给未登录的人用），但要限流防刷。
    */
    Route::prefix('member')->group(function () {

        Route::post('/register', [MemberAuthController::class, 'register'])
            ->middleware('throttle:member-register');

        Route::post('/login', [MemberAuthController::class, 'login'])
            ->middleware('throttle:member-login');

        Route::middleware('auth:member')->group(function () {
            Route::get('/me', [MemberAuthController::class, 'me']);
            Route::post('/logout', [MemberAuthController::class, 'logout']);

            /*
            | 我的博客（模块 10 R12）。
            |
            | 路由参数用数字 id（`{blog}`），不是 slug —— 这是**写接口**，
            | 目标一定是"我自己那篇"，用 id 更直接；而已发布的 slug 允许为空段落。
            | ⚠️ `/blogs/stats` 必须在 `/blogs/{blog}` 之前注册吗？不需要 ——
            |    本组里没有 `GET /blogs/{blog}`（详情是 public 的），所以不冲突。
            |    但将来若要加"按 id 看自己的某一篇"，记得把它放到 stats 后面。
            */
            Route::get('/blogs', [MemberBlogController::class, 'index']);
            Route::get('/blogs/stats', [MemberBlogController::class, 'stats']);
            Route::post('/blogs', [MemberBlogController::class, 'store']);
            Route::put('/blogs/{blog}', [MemberBlogController::class, 'update']);
            Route::delete('/blogs/{blog}', [MemberBlogController::class, 'destroy']);
            Route::post('/blogs/{blog}/publish', [MemberBlogController::class, 'publish']);

            // 互动：`type` 走 body（POST）/ query（DELETE），理由见控制器注释
            Route::post('/blogs/{blog}/reactions', [MemberBlogController::class, 'react']);
            Route::delete('/blogs/{blog}/reactions', [MemberBlogController::class, 'unreact']);
        });

    });

    /*
    | 管理端。
    |
    | 守卫是 **admin**（不是默认的 sanctum）：它绑定 admins 表，与前台会员的 users 表
    | 分开，避免会员令牌穿过这一层（需求 D1）。细节见 docs/modules/01-auth.md。
    */
    Route::prefix('admin')->group(function () {

        /*
        | 登录：**不能**挂 auth:admin（它本来就是给未登录的人用的），
        | 但必须挂限流 —— 否则就是一个可以无限次试密码的接口。
        | 限流规则定义在 AppServiceProvider::boot()，key 是"账号 + IP"。
        */
        Route::post('/login', [AdminAuthController::class, 'login'])
            ->middleware('throttle:admin-login');

        // 以下都要 Bearer 令牌
        Route::middleware('auth:admin')->group(function () {
            Route::get('/me', [AdminAuthController::class, 'me']);

            // 只取"我自己的权限列表"：前端刷新页面时用它补，收到 403 后用它重拉
            Route::get('/me/permissions', [AdminAuthController::class, 'permissions']);

            Route::post('/logout', [AdminAuthController::class, 'logout']);

            /*
            | 权限（模块 08 Rbac）。
            |
            | 路由参数一律用**数字 id**，不用权限点的 key —— key 允许改名，
            | 一改 URL 就全变了（这正是 pivot 存 ID 的同一个理由）。
            | 读接口走预加载层（不查业务表），写接口内部会 flush 缓存。
            */
            Route::get('/permissions', [PermissionController::class, 'index']);
            Route::post('/permissions', [PermissionController::class, 'store']);
            Route::patch('/permissions/{permission}', [PermissionController::class, 'update']);
            Route::delete('/permissions/{permission}', [PermissionController::class, 'destroy']);

            Route::get('/roles', [RoleController::class, 'index']);
            Route::post('/roles', [RoleController::class, 'store']);
            Route::patch('/roles/{role}', [RoleController::class, 'update']);
            Route::delete('/roles/{role}', [RoleController::class, 'destroy']);

            /*
            | 博客管理（模块 10 R19）。
            |
            | 两条路由的权限**刻意不同**，别图省事合并：
            |   `blog.read`    看列表 —— owner / admin / moderator / blogger 都有
            |   `blog.publish` 下架/恢复 —— **只有 owner 有**
            | 这是设计意图（见 docs/permissions.md 的角色基线表）。
            | 把下架也挂到 `blog.read` 上，等于让任何管理员都能下架别人的内容。
            */
            Route::get('/blogs', [AdminBlogController::class, 'index'])
                ->middleware('permission:blog.read');

            Route::post('/blogs/{blog}/hide', [AdminBlogController::class, 'hide'])
                ->middleware('permission:blog.publish');
        });

    });

    /*
    | EA 客户端：签名 + 授权码 + 幂等。
    | 统一入口 POST /client/ea/verify，另加别名路由 /client/ea/{product_code}/verify
    | 内部转发到同一处理器；驱动体系落在 app/Modules/License/Drivers。
    | 按用户要求，**这块暂不实现，后续单独详细规划**（见 docs/modules/04-client.md）。
    */
    Route::prefix('client')->group(function () {
        //
    });

});
