<?php

declare(strict_types=1);

/**
 * MemberRegisterRequest —— 会员注册的入参校验
 *
 * 本文件属于 core（纯 API 后端）的 Member 模块。
 * 用途：只做入参形状校验（必填、类型、长度、邮箱唯一）。
 *       校验失败由全局异常处理器统一转成 `SYS_VALIDATION` + `meta.errors`，
 *       所以这里**不要**写 `failedValidation()`。
 * 谁在调：`MemberAuthController::register()`。
 *
 * 这里**不查账号是否存在、不写业务规则** —— 业务在 `MemberAuthService`。
 * 字段显示名（邮箱/密码/确认密码）在 `lang/zh_CN/validation.php` 的 `attributes`。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/07-member.md
 */

namespace App\Modules\Member\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MemberRegisterRequest extends FormRequest
{
    /**
     * 判断调用者是否有权发这个请求。
     *
     * 用法：
     *   // 无需手动调用
     *
     * 边界/注意：
     *   恒为 true —— 注册本来就是给**未登录**的人用的，鉴权由路由（无守卫）表达。
     *
     * @return bool 恒为 true
     *
     * @version 0.1.0
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
     *   POST /api/v1/member/register
     *   { "name": "张三", "email": "a@b.com", "password": "11111", "password_confirmation": "11111" }
     *
     * 边界/注意：
     *   1. `email` 的 `unique:users,email` 在这里就挡掉重复注册，
     *      返回 422 + `meta.errors.email = ["邮箱 已存在。"]`。
     *   2. `password` 用 `confirmed`（要求 `password_confirmation` 一致）。
     *      **不设长度下限**：一期为了本地测试方便（`11111` 也能用）；
     *      上线前要在这里补 `min:8` 这类强度规则（见 07-member.md 待办）。
     *   3. `name` 可空，空则由服务端用邮箱前缀兜底。
     *
     * @return array<string, array<int, string>> 规则数组
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    上线前补密码强度规则（min:8 等）
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:64'],
            'email' => ['required', 'string', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'max:255', 'confirmed'],
        ];
    }
}
