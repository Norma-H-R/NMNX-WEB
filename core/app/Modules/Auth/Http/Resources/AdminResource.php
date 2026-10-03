<?php

declare(strict_types=1);

/**
 * AdminResource —— 后台管理者的出参整形
 *
 * 本文件属于 core（纯 API 后端）的 Auth 模块。
 * 用途：把 `Admin` 模型**逐字段显式**转成接口出参。
 *       为什么不让模型直接序列化：模型的字段一改（比如将来加 `internal_note`），
 *       接口会**静默**多吐一个字段出去。显式列字段能挡住这类泄漏。
 * 谁在调：`AdminAuthController` 的 `login()` / `me()`。
 *
 * 本类**不做**权限判断、不查库、不加业务字段 —— 只做字段搬运。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/01-auth.md
 */

namespace App\Modules\Auth\Http\Resources;

use App\Modules\Auth\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Admin
 */
final class AdminResource extends JsonResource
{
    /**
     * 转换成出参数组。
     *
     * 用法：
     *   // 控制器里：
     *   return ApiResponse::ok(['admin' => new AdminResource($admin)]);
     *
     *   // 出参（不含统一响应体外壳）：
     *   { "id":1, "username":"root", "name":"管理员", "email":null,
     *     "status":"active", "last_login_at":"2026-10-03T14:05:00+08:00" }
     *
     * 边界/注意：
     *   1. `password` / `remember_token` 在模型上是 `#[Hidden]`，但这里也**不要**列它们 ——
     *      资源的职责是"只列出该出去的"，不要依赖模型藏字段。
     *   2. `status` 出的是**枚举的 value**（`active`），不是 `label()`（`正常`）。
     *      value 是协议、label 是文案，文案会改，协议不会。
     *   3. `last_login_at` 输出 ISO8601；从没登录过时为 `null`（不是空串）。
     *
     * @param  Request  $request  当前请求（本资源未用到）
     * @return array<string, mixed> 出参字段
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    加 `abilities`（该账号的权限标识列表），等模块 08 Rbac 落地后补
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status->value,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
