<?php

declare(strict_types=1);

/**
 * MemberLoginRequest —— 会员登录的入参校验
 *
 * 本文件属于 core（纯 API 后端）的 Member 模块。
 * 用途：只做入参形状校验（**邮箱或手机号二选一** + 密码）。
 *       校验失败由全局异常处理器统一转成 `SYS_VALIDATION`。
 * 谁在调：`MemberAuthController::login()`。
 *
 * 为什么同时收 email / phone：
 *   前端用户中心有两种登录方式（手机号 / 邮箱），共用同一个登录接口。
 *   用 `required_without` 保证"两个至少给一个"，而不是"两个都必须给"。
 *
 * ⚠️ `phone` **不做数字校验**（需求：测试期统一填 `11111`）——
 *    手机号在这里只是一个**登录标识**，不是需要格式正确的电话号码。
 *    将来真接短信登录时，格式校验要加在"发验证码"那一步，不是登录这一步。
 *
 * @version 0.2.0
 *
 * @since   2026-10-03
 * @see     docs/modules/07-member.md
 */

namespace App\Modules\Member\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MemberLoginRequest extends FormRequest
{
    /**
     * 判断调用者是否有权发这个请求。
     *
     * 用法：
     *   // 无需手动调用
     *
     * @return bool 恒为 true（登录接口本就是给未登录的人用的）
     *
     * @version 0.2.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 入参规则。
     *
     * 用法：
     *   POST /api/v1/member/login
     *   { "phone": "11111", "password": "11111", "device_name": "web" }   ← 手机号登录
     *   { "email": "a@b.com", "password": "11111" }                        ← 邮箱登录
     *
     * 边界/注意：
     *   1. `required_without` 是"另一个为空时这个必填"，所以**两个都传也可以**，
     *      此时以 `phone` 为准（服务里的解析顺序）。
     *   2. `password` 不设下限：不泄露密码策略、兼容旧账号，也方便测试用 `11111`。
     *
     * @return array<string, array<int, string>> 规则数组
     *
     * @version 0.2.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function rules(): array
    {
        return [
            'phone' => ['nullable', 'string', 'max:20', 'required_without:email'],
            'email' => ['nullable', 'string', 'email', 'max:190', 'required_without:phone'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:64'],
        ];
    }
}
