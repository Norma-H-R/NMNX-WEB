<?php

declare(strict_types=1);

/**
 * AdminLoginRequest —— 后台登录的入参校验
 *
 * 本文件属于 core（纯 API 后端）的 Auth 模块。
 * 用途：只做**入参形状**校验（必填、类型、长度）。校验失败由全局异常处理器
 *       统一转成 `SYS_VALIDATION` + `meta.errors`，所以这里**不要**写
 *       `failedValidation()` —— 那是"每个 Request 重写一遍"的重复代码，
 *       已经由 `bootstrap/app.php` 一处解决。
 * 谁在调：`AdminAuthController::login()`。
 *
 * ⚠️ 这里**不校验账号是否存在、密码对不对**：
 *   那属于业务规则，在 `AdminAuthService` 里。分层见 docs/standards.md 第 2.4 节。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/01-auth.md
 */

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AdminLoginRequest extends FormRequest
{
    /**
     * 判断调用者是否有权发这个请求。
     *
     * 用法：
     *   // 无需手动调用，FormRequest 在控制器之前自动执行
     *
     * 边界/注意：
     *   这里**恒为 true**，是刻意的：登录接口本来就是给未登录的人用的，
     *   "谁能调"由路由（无守卫）表达，不是由 authorize() 表达。
     *   如果哪天这里要写权限判断，说明接口放错了位置。
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
     *   POST /api/v1/admin/login
     *   { "username": "root", "password": "……", "device_name": "chrome" }
     *
     * 边界/注意：
     *   1. `username` 用 `max:64` 与 `admins.username` 的列宽对齐 ——
     *      校验上限比列宽松，超长会在写入时才报数据库错。
     *   2. `password` **不做长度下限**校验。设长度规则等于泄露了密码策略，
     *      而且老账号可能不满足新策略，会被永久锁在门外。密码能否通过由 `Hash::check` 说了算。
     *
     * @return array<string, array<int, string>> 规则数组
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:64'],
        ];
    }

    /*
     * 这里**故意没有** `attributes()`。
     *
     * 字段显示名（账号 / 密码 / 设备名）统一定义在 `lang/zh_CN/validation.php`
     * 的 `attributes` 里 —— 那是**全站共用**的一张表。
     * 在这里再写一份就是同一信息两处维护：改了语言包忘了这里，
     * 会出现"同一个字段在两个接口里叫法不同"。
     *
     * 只有**本模块特有的叫法**才需要在这里覆盖 `attributes()`。
     * 文案中文化的整体方案见 docs/standards.md 与 lang/zh_CN/validation.php 的文件注释。
     */
}
