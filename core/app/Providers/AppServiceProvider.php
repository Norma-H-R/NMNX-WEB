<?php

declare(strict_types=1);

/**
 * AppServiceProvider —— 应用级装配
 *
 * 本文件属于 core（纯 API 后端）的全局基础设施。
 * 用途：放**不属于任何一个业务模块**的应用级配置。
 *       目前只有限流规则；模块自己的绑定一律放在 `app/Modules/<Name>/<Name>ServiceProvider.php`
 *       （见 `CryptoServiceProvider`），**不要往这里堆**。
 * 谁在调：Laravel 通过 `bootstrap/providers.php` 自动加载。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/README.md
 */

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * 注册容器绑定。
     *
     * 用法：
     *   // 目前无需绑定，保持空实现
     *
     * 边界/注意：
     *   当前**故意为空**。业务绑定请放各模块自己的 ServiceProvider，
     *   这里只在"确实没有归属模块"时才加东西。
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
        //
    }

    /**
     * 启动阶段的动作。
     *
     * 用法：
     *   // 由框架自动调用
     *
     * 边界/注意：
     *   限流规则在这里定义，路由上只写名字（`throttle:admin-login`）——
     *   阈值改一次就全站生效，不用去翻每个路由文件。
     *
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * 定义接口限流规则。
     *
     * 用法：
     *   // 定义后由路由上的中间件引用：
     *   Route::post('/login', ...)->middleware('throttle:admin-login');
     *
     * 边界/注意：
     *   1. 限流的 key 是 **"账号 + IP"**，不是只按 IP。
     *      只按 IP 的话，同一个办公室（共用出口 IP）的人会互相拖累；
     *      只按账号的话，攻击者能靠疯狂试某个账号把**真正的管理员锁在门外**（拒绝服务）。
     *      两个一起用是折中：单 IP 猜单账号会被挡，跨 IP 打同一账号不会被单点锁死。
     *   2. 超出阈值时 ThrottleRequests 抛 429，会被 `bootstrap/app.php` 统一映射成
     *      `SYS_RATE_LIMITED` —— 所以**不需要**自己写限流响应。
     *   3. 用户名先 `lower` 再拼，避免 `Root` / `root` 被算成两个计数器。
     *
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    单账号的失败次数累计封禁（跨 IP），等 Audit 模块落地后一起做
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('admin-login', function (Request $request): Limit {
            $key = Str::lower((string) $request->input('username')).'|'.$request->ip();

            return Limit::perMinute(5)->by(Str::transliterate($key));
        });

        /*
         * 会员登录：key 是"**登录标识 + IP**"，与 admin-login 同理（防爆破 + 防单点锁死）。
         *
         * ⚠️ 标识必须"手机号优先、退回邮箱"：登录接口两者收其一，
         * 如果只取 `email`，手机号登录时它为空 —— key 会退化成 `'|IP'`，
         * 于是**所有手机号共用同一个桶**（5 次/分钟按 IP 全局算），
         * 换个人登录就被前一个人牵连。这是实测踩到过的坑，回归测试见
         * `MemberAuthTest::test_rate_limit_is_per_identifier`。
         */
        RateLimiter::for('member-login', function (Request $request): Limit {
            $identifier = $request->input('phone') ?: $request->input('email');
            $key = Str::lower((string) $identifier).'|'.$request->ip();

            return Limit::perMinute(5)->by(Str::transliterate($key));
        });

        // 会员注册：key 只按 IP —— 注册阶段还没有可信账号标识，防"批量刷号"只能按 IP。
        // 同 IP 每分钟 5 个新号；共用公网出口会有误伤，但注册频率本来就低。
        RateLimiter::for('member-register', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
