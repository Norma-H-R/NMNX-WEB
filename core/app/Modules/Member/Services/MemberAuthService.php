<?php

declare(strict_types=1);

/**
 * MemberAuthService —— 会员注册 / 登录的业务规则
 *
 * 本文件属于 core（纯 API 后端）的 Member 模块。
 * 用途：**本模块唯一的业务规则所在地**。注册能不能成、登录怎么判、登出删什么，都在这里。
 *       控制器只做 HTTP 翻译，模型（`App\Models\User`）只管数据读写。
 * 谁在调：`MemberAuthController`。
 *
 * 登录方式（需求 C-6，已定案 2026-10-03）：
 *   一期只实现**邮箱 + 密码**（默认账户）。
 *   二维码（微信 / Telegram）、短信登录是**预留接口**，后面再做 ——
 *   到那时给这里加 `loginByQr()` / `loginBySms()` 方法即可，别改现有签名。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/07-member.md
 */

namespace App\Modules\Member\Services;

use App\Models\User;
use App\Modules\Member\Enums\MemberStatus;
use App\Modules\Rbac\Models\Role;
use App\Modules\Support\ErrorCode;
use App\Modules\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

final class MemberAuthService
{
    /** 新注册会员挂的角色（Rbac 的默认身份，见 RbacSeeder） */
    private const DEFAULT_ROLE = 'member';

    /**
     * 注册一个新会员，并直接签发令牌（注册即登录）。
     *
     * 用法：
     *   $result = app(MemberAuthService::class)->register('张三', 'a@b.com', '11111', $request->ip());
     *   $result['user'];   // User 模型（已落库，status=active）
     *   $result['token'];  // 明文令牌，只出现这一次
     *
     * 边界/注意：
     *   1. `email` 的唯一性**不在本方法里查**，由 `MemberRegisterRequest` 的
     *      `unique:users,email` 规则在进服务前就挡掉了 —— 这里假设入参已经合法。
     *   2. `name` 传空时用邮箱的 `@` 前缀兜底，避免"必须填昵称"打断注册流程。
     *   3. 注册默认身份是"普通用户"（需求 D1）。挂 RBAC 的 `user` 角色这件事
     *      等模块 08 Rbac 落地后在这里补（现在是 @todo）。
     *
     * @param  string  $name  昵称，可为空
     * @param  string  $email  邮箱（唯一，登录标识）
     * @param  string  $password  明文密码（由模型 hashed cast 自动哈希）
     * @param  string|null  $ip  客户端 IP，记入 last_login_ip
     * @return array{user: User, token: string} 会员模型 + 明文令牌
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    与模块 08 Rbac 对接：注册后自动挂 `user` 角色
     */
    public function register(string $name, string $email, string $password, ?string $ip = null): array
    {
        $user = new User;
        $user->name = $name !== '' ? $name : Str::before($email, '@');
        $user->email = $email;
        $user->password = $password;
        $user->status = MemberStatus::Active;
        // 挂上默认角色：没有角色的人**一个权限都没有**，
        // 而他连"博主"这种前台能力都要靠角色给（forum.post.create 等）
        $user->role_id = Role::query()->where('key', self::DEFAULT_ROLE)->value('id');
        $user->last_login_at = now();
        $user->last_seen_at = now();
        $user->last_login_ip = $ip;
        $user->save();

        $token = $user->createToken('register')->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }

    /**
     * 手机号 / 邮箱 + 密码登录。
     *
     * 用法：
     *   // 手机号登录（前端"手机号"方式；测试期 phone 统一填 11111）
     *   $result = app(MemberAuthService::class)->login(null, '11111', '11111', 'web', $request->ip());
     *   // 邮箱登录（前端"邮箱"方式）
     *   $result = app(MemberAuthService::class)->login('a@b.com', null, '11111');
     *
     * 边界/注意（三条安全处理，与 AdminAuthService 完全一致，改之前先读那边）：
     *   1. 账号不存在与密码错抛**同一个**错误码（防账号枚举）。
     *   2. 状态检查放在密码校验**之后**（防"不用密码问出账号是否被禁用"）。
     *   3. 账号不存在时也跑一次 `Hash::check`（临时哈希），把两条分支耗时拉平。
     *   4. **有手机号就按手机号找**、忽略邮箱；两个都空则查不到，走"凭据错误"分支。
     *
     * @param  string|null  $email  登录邮箱（与 $phone 至少给一个）
     * @param  string|null  $phone  登录手机号；给了它就按它找，优先于 $email
     * @param  string  $password  明文密码
     * @param  string|null  $deviceName  设备名（令牌备注），空则用 'member'
     * @param  string|null  $ip  客户端 IP
     * @return array{user: User, token: string} 会员模型 + 明文令牌
     *
     * @throws ApiException `AUTH_INVALID_CREDENTIALS` / `AUTH_ACCOUNT_DISABLED`
     *
     * @version 0.2.0
     *
     * @since   2026-10-03
     *
     * @todo    补 qr / sms（验证码）登录方法（预留接口）
     */
    public function login(?string $email, ?string $phone, string $password, ?string $deviceName = null, ?string $ip = null): array
    {
        $query = User::query();

        // 有手机号就按手机号找，否则按邮箱找（两个都传时以手机号为准）
        if ($phone !== null && $phone !== '') {
            $query->where('phone', $phone);
        } else {
            $query->where('email', $email);
        }

        $user = $query->first();

        $hash = $user?->password ?? Hash::make('nmnx-timing-equalizer');

        if (! Hash::check($password, $hash) || ! $user) {
            throw new ApiException(ErrorCode::AUTH_INVALID_CREDENTIALS);
        }

        if (! $user->status->canLogin()) {
            throw new ApiException(ErrorCode::AUTH_ACCOUNT_DISABLED);
        }

        $token = $user->createToken($deviceName ?: 'member')->plainTextToken;

        $user->last_login_at = now();
        $user->last_seen_at = now();
        $user->last_login_ip = $ip;
        $user->save();

        return ['user' => $user, 'token' => $token];
    }

    /**
     * 吊销当前请求所用的令牌（只登出当前设备）。
     *
     * 用法：
     *   app(MemberAuthService::class)->logout($request->user('member'));
     *
     * 边界/注意：
     *   只删**当前**令牌；令牌模型走 `Sanctum::$personalAccessTokenModel`，
     *   会话态（TransientToken）没有 delete()，所以要判类型。
     *
     * @param  User  $user  当前登录的会员
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();
        $tokenModel = Sanctum::$personalAccessTokenModel;

        if ($token instanceof $tokenModel) {
            $token->delete();
        }
    }
}
