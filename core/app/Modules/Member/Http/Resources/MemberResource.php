<?php

declare(strict_types=1);

/**
 * MemberResource —— 会员的出参整形
 *
 * 本文件属于 core（纯 API 后端）的 Member 模块。
 * 用途：把 `User` 模型**逐字段显式**转成接口出参，防止模型字段变动时接口静默多吐字段。
 * 谁在调：`MemberAuthController` 的注册 / 登录 / me。
 *
 * 本类**不做**权限判断、不查库 —— 只做字段搬运。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/07-member.md
 */

namespace App\Modules\Member\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class MemberResource extends JsonResource
{
    /**
     * 转换成出参数组。
     *
     * 用法：
     *   return ApiResponse::ok(['member' => new MemberResource($user)]);
     *
     *   // 出参（不含统一响应体外壳）：
     *   { "id":1, "name":"南门会员", "email":"test@example.com", "phone":"11111",
     *     "status":"active", "tier":"silver", "points":2860,
     *     "email_verified_at":"…", "last_login_at":"…", "created_at":"…" }
     *
     * 边界/注意：
     *   1. `password` / `remember_token` 在模型上是 `#[Hidden]`，这里也**不列**它们。
     *   2. `status` 出的是枚举 **value**（`active`），不是 `label()`（`正常`）。
     *   3. `tier` 出的是**等级代码**（`silver`），中文由前端映射 —— 接口不吐展示文案。
     *   4. `created_at` 就是前端要的"加入时间"，**不要**再加一个 `joined_at` 字段
     *      （同一件事两个名字，迟早对不上）。
     *
     * @param  Request  $request  当前请求（本资源未用到）
     * @return array<string, mixed> 出参字段
     *
     * @version 0.2.0
     *
     * @since   2026-10-03
     *
     * @todo    补 `roles`（会员的角色列表），等模块 08 Rbac 落地后加
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status->value,
            'tier' => $this->tier,
            'points' => $this->points,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
