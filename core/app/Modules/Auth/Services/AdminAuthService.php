<?php

declare(strict_types=1);

/**
 * AdminAuthService —— 后台登录的业务规则
 *
 * 本文件属于 core（纯 API 后端）的 Auth 模块。
 * 用途：**本模块唯一的业务规则所在地** —— 登录能不能成功、令牌怎么签、登出删什么，
 *       全部在这里。控制器只做 HTTP 翻译，模型只管数据读写（见 docs/standards.md 第 2.4 节）。
 * 谁在调：`AdminAuthController`。
 *
 * 不负责：限流（那是路由上的 `throttle` 中间件）、入参形状校验（那是 `AdminLoginRequest`）。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/01-auth.md
 */

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\Admin;
use App\Modules\Support\ErrorCode;
use App\Modules\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

final class AdminAuthService
{
    /**
     * 校验凭据并签发令牌。
     *
     * 用法：
     *   $result = app(AdminAuthService::class)->login('root', '明文密码', 'chrome', $request->ip());
     *   $result['admin'];   // Admin 模型（已更新 last_login_*）
     *   $result['token'];   // '1|xxxxxxxx' —— 只在这一刻能拿到明文令牌
     *
     * 边界/注意：
     *   1. **账号不存在与密码错误抛同一个错误码**（`AUTH_INVALID_CREDENTIALS`），
     *      否则攻击者能靠错误码差异枚举出哪些账号存在。
     *   2. **状态检查放在密码校验之后**。先查状态再查密码，等于"不用密码就能问出
     *      某个账号是否存在且被禁用"，又是枚举。
     *   3. 账号不存在时也会跑一次 `Hash::check`（用临时生成的哈希），
     *      让两条分支的**耗时相近** —— 否则响应快慢本身就是枚举信号。
     *   4. 明文令牌只在返回值里出现这一次，数据库里存的是哈希，之后再也取不回来。
     *
     * @param  string  $username  登录账号
     * @param  string  $password  明文密码
     * @param  string|null  $deviceName  设备名，作为令牌的 name；空则用 'admin'
     * @param  string|null  $ip  客户端 IP，记入 last_login_ip
     * @return array{admin: Admin, token: string} 管理员模型 + 明文令牌
     *
     * @throws ApiException `AUTH_INVALID_CREDENTIALS` / `AUTH_ACCOUNT_DISABLED`
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    登录成功后写审计日志（模块 05 Audit 落地后接上）
     */
    public function login(string $username, string $password, ?string $deviceName = null, ?string $ip = null): array
    {
        $admin = Admin::query()->where('username', $username)->first();

        // 无论账号存不存在都跑一次哈希校验，把两条分支的耗时拉平（防时序枚举）
        $hash = $admin?->password ?? Hash::make('nmnx-timing-equalizer');

        if (! Hash::check($password, $hash) || ! $admin) {
            throw new ApiException(ErrorCode::AUTH_INVALID_CREDENTIALS);
        }

        // 到这里密码已经对了，才允许区分"被禁用"
        if (! $admin->status->canLogin()) {
            throw new ApiException(ErrorCode::AUTH_ACCOUNT_DISABLED);
        }

        $token = $admin->createToken($deviceName ?: 'admin')->plainTextToken;

        // last_login_* 不在 #[Fillable] 里（不该由客户端填），所以直接赋值
        $admin->last_login_at = now();
        $admin->last_login_ip = $ip;
        $admin->save();

        return ['admin' => $admin, 'token' => $token];
    }

    /**
     * 吊销当前这次请求所用的令牌（只登出当前设备）。
     *
     * 用法：
     *   app(AdminAuthService::class)->logout($request->user());
     *
     * 边界/注意：
     *   1. 只删**当前令牌**，不是把这个账号的所有令牌都删掉 ——
     *      否则在一台设备登出会把其它设备一起踢下线。
     *   2. 令牌模型走 `Sanctum::$personalAccessTokenModel` 读，
     *      不要在代码里写死 `PersonalAccessToken::class`（可被配置替换）。
     *   3. 会话态登录（`TransientToken`）**没有 delete()**，所以这里要判类型。
     *      我们走 Bearer 令牌，正常不会走到那分支，但不能假设。
     *
     * @param  Admin  $admin  当前登录者
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function logout(Admin $admin): void
    {
        $token = $admin->currentAccessToken();
        $tokenModel = Sanctum::$personalAccessTokenModel;

        if ($token instanceof $tokenModel) {
            $token->delete();
        }
    }
}
