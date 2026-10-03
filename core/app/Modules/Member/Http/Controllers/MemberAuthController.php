<?php

declare(strict_types=1);

/**
 * MemberAuthController —— 会员鉴权的 HTTP 入口
 *
 * 本文件属于 core（纯 API 后端）的 Member 模块。
 * 用途：**只做 HTTP 翻译** —— 取参 → 交给 Request 校验 → 调 Service → 用 ApiResponse 出参。
 *       不出现 SQL、不出现业务分支（见 docs/standards.md 第 2.4 节）。
 * 谁在调：`routes/api.php` 里 `/api/v1/member/*` 四条路由。
 *
 * 刻意**不继承** `App\Http\Controllers\Controller`（理由同 AdminAuthController）。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/07-member.md
 */

namespace App\Modules\Member\Http\Controllers;

use App\Models\User;
use App\Modules\Member\Http\Requests\MemberLoginRequest;
use App\Modules\Member\Http\Requests\MemberRegisterRequest;
use App\Modules\Member\Http\Resources\MemberResource;
use App\Modules\Member\Services\MemberAuthService;
use App\Modules\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberAuthController
{
    /**
     * 构造：注入业务服务。
     *
     * 用法：
     *   // 由容器自动注入
     *
     * @param  MemberAuthService  $auth  注册 / 登录 / 登出的业务规则
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function __construct(private readonly MemberAuthService $auth) {}

    /**
     * 注册：邮箱 + 密码创建会员，并直接签发令牌（注册即登录）。
     *
     * 用法：
     *   POST /api/v1/member/register
     *   { "name": "张三", "email": "a@b.com", "password": "11111", "password_confirmation": "11111" }
     *
     *   200 → data: { "token": "1|xxxx", "member": { … } }
     *   422 → SYS_VALIDATION（邮箱重复时 meta.errors.email = ["邮箱 已存在。"]）
     *   429 → SYS_RATE_LIMITED
     *
     * 边界/注意：
     *   注册即登录（直接签令牌），省掉"注册完再去登录"这一步。
     *   响应码是 200 而非 201 —— 一期不区分，前端也不依赖这个（见 07-member.md 待办）。
     *
     * @param  MemberRegisterRequest  $request  已校验的入参
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function register(MemberRegisterRequest $request): JsonResponse
    {
        $result = $this->auth->register(
            name: (string) $request->string('name', ''),
            email: (string) $request->string('email'),
            password: (string) $request->string('password'),
            ip: $request->ip(),
        );

        return ApiResponse::ok([
            'token' => $result['token'],
            'member' => new MemberResource($result['user']),
        ]);
    }

    /**
     * 登录：手机号 / 邮箱 + 密码 → 令牌。
     *
     * 用法：
     *   POST /api/v1/member/login
     *   { "phone": "11111", "password": "11111", "device_name": "web" }   ← 手机号登录
     *   { "email": "a@b.com", "password": "11111" }                        ← 邮箱登录
     *
     *   200 → data: { "token": "1|xxxx", "member": { … } }
     *   401 → AUTH_INVALID_CREDENTIALS（账号不存在或密码错，不区分）
     *   403 → AUTH_ACCOUNT_DISABLED
     *   429 → SYS_RATE_LIMITED
     *
     * @param  MemberLoginRequest  $request  已校验的入参
     * @return JsonResponse 统一响应体
     *
     * @version 0.2.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function login(MemberLoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            email: $request->string('email')->toString() ?: null,
            phone: $request->string('phone')->toString() ?: null,
            password: (string) $request->string('password'),
            deviceName: $request->string('device_name')->toString() ?: null,
            ip: $request->ip(),
        );

        return ApiResponse::ok([
            'token' => $result['token'],
            'member' => new MemberResource($result['user']),
        ]);
    }

    /**
     * 登出：吊销本次请求所用的令牌。
     *
     * 用法：
     *   POST /api/v1/member/logout     （需带 Bearer 令牌）
     *
     *   200 → data: {}
     *
     * @param  Request  $request  当前请求
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('member');

        $this->auth->logout($user);

        return ApiResponse::ok();
    }

    /**
     * 当前登录会员的信息（官网 / 社区前端做页面初始化用）。
     *
     * 用法：
     *   GET /api/v1/member/me          （需带 Bearer 令牌）
     *
     *   200 → data: { "member": { … } }
     *
     * 边界/注意：
     *   出参统一包在 `member` 键里，与登录/注册的 `data.member` 一致。
     *
     * @param  Request  $request  当前请求
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('member');

        return ApiResponse::ok(['member' => new MemberResource($user)]);
    }
}
