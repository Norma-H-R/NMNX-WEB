<?php

declare(strict_types=1);

use App\Modules\Support\ErrorCode;
use App\Modules\Support\Exceptions\ApiException;
use App\Modules\Support\Http\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 应用引导
 *
 * 本文件是 core（纯 API 后端）的装配中心，管三件事：
 *   1. `withRouting()`   —— 挂载 web / api 路由、命令、健康检查
 *   2. `withMiddleware()` —— 中间件全局策略（**含那个不能删的 redirectGuestsTo**）
 *   3. `withExceptions()` —— 把框架异常统一转成 ApiResponse 的结构
 *
 * @version 0.2.0
 *
 * @since   2026-10-03
 * @see     docs/README.md
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // 之前没注册 api:，所以 /api/* 一直是 404。纯 API 后端必须挂上。
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * 这一行是纯 API 后端的必需品，删掉就会 500。
         *
         * Laravel 13 在 ApplicationBuilder::withMiddleware() 里默认写死了：
         *     (new Middleware)->redirectGuestsTo(fn () => route('login'))
         *
         * 未认证时 Illuminate\Auth\Middleware\Authenticate 会执行：
         *     $request->expectsJson() ? null : $this->redirectTo($request)
         * 于是触发上面那个闭包 -> route('login')。我们根本没有 login 路由，
         * 这里直接抛 RouteNotFoundException，客户端拿到 500，而且这个异常发生在
         * 进入异常处理器之前，所以 shouldRenderJsonWhen / 自定义 render 都拦不住。
         *
         * 换成 null：redirectTo() 返回 null -> 抛出不带重定向目标的
         * AuthenticationException -> 由下面的 render 统一转成 401 统一响应体。
         */
        $middleware->redirectGuestsTo(fn () => null);

        /*
         * 纯 API 后端**不加密 Cookie**：把 EncryptCookies 从 web 组里拿掉。
         *
         * 为什么拿得掉：项目只用 Bearer 令牌鉴权（Sanctum），没有任何 Cookie 需要加密。
         * 而 web 组里的 EncryptCookies **每次响应**都会调 `openssl_encrypt` 去加密 Cookie。
         *
         * 这条是 2026-10-03 排查"`http://127.0.0.1:8000/` 返回 500"时加的。当时的报错：
         *     ArgumentCountError: mysqli_num_rows() expects exactly 1 argument, 6 given
         *   堆栈落在 `Encrypter.php:108` —— 那一行明明是 `\openssl_encrypt(...)`（正好 6 个参数），
         *   却被派发到了 `mysqli_num_rows`。即**服务器进程内部的函数表错位了**。
         *   已证实**不是 PHP 安装的问题**：新进程里直接调 `openssl_encrypt` 完全正常。
         *   拿掉这条中间件后，根地址不再碰 openssl，500 消失。
         *
         * ⚠️ 这是**绕开**，不是**根治**：那个进程仍然处于错乱状态，
         *    任何真正用到 openssl 的地方（`Crypt::encrypt()`、将来 06 加密模块）照样会炸。
         *    **根治办法是重启后端进程**：Ctrl+C 之后重新 `php artisan serve`。
         */
        $middleware->web(remove: [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
        ]);

        /*
         * 鉴权通道的别名 —— **全项目唯一的权限判定入口**。
         *
         * 用法（路由上声明式）：
         *   Route::delete('/posts/{post}', ...)->middleware('permission:forum.post.delete');
         *
         * 为什么不直接用 Laravel 的 `can:`：
         *   `can:` 走 Gate，而 Gate 解析"当前用户"用的是**默认守卫**。
         *   我们这个项目是多守卫（admin / member），默认守卫取不到人就一律 403 ——
         *   会变成"明明有权限却过不去"这种最难查的故障。
         *   自己的中间件能显式按 admin → member 的顺序取主体，行为可预测。
         *
         * 为什么必须**只有这一个**入口：
         *   散落的 `if (! can(...))` 必然漏；漏掉的那个接口就是裸奔的，
         *   而且从代码上完全看不出来。声明在路由上，一眼可查。
         */
        $middleware->alias([
            'permission' => \App\Modules\Rbac\Http\Middleware\EnsurePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // api/* 一律渲染 JSON，绝不返回 HTML 错误页，也绝不重定向。
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * 业务异常 —— Support 模块的 ApiException 携带错误码，
         * HTTP 状态码由 ErrorCode::httpStatus() 反查，对应关系只在 ErrorCode 维护。
         */
        $exceptions->render(function (ApiException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::fail($e->errorCode(), $e->meta(), $e->getMessage());
            }
        });

        // 表单校验失败 —— 统一成 SYS_VALIDATION，明细放 meta.errors。
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::fail(ErrorCode::SYS_VALIDATION, ['errors' => $e->errors()]);
            }
        });

        // 未认证 —— 统一成 SYS_UNAUTHENTICATED（401）。
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::fail(ErrorCode::SYS_UNAUTHENTICATED);
            }
        });

        // 路由找不到 —— 统一成 SYS_NOT_FOUND。
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::fail(ErrorCode::SYS_NOT_FOUND);
            }
        });

        /*
         * 其它 HTTP 异常（405 / 429 等）—— 按状态码映射到对应错误码。
         * 映射表在这里就地维护：它是"框架状态码 → 我们的错误码"的桥，
         * 不属于 ErrorCode（那边只认自己的常量）。
         */
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $code = match ($e->getStatusCode()) {
                405 => ErrorCode::SYS_METHOD_NOT_ALLOWED,
                429 => ErrorCode::SYS_RATE_LIMITED,
                403 => ErrorCode::SYS_FORBIDDEN,
                default => ErrorCode::SYS_INTERNAL,
            };

            return ApiResponse::fail($code);
        });
    })->create();
