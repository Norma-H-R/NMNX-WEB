<?php

declare(strict_types=1);

/**
 * RoleRequest —— 角色新增 / 修改的入参校验
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块。
 * 用途：只校验**形状**。业务规则（key 自动生成、owner 不许改基线、权限点必须存在、
 *       删角色前把人转走）在 `RbacService` 里。
 * 谁在调：`RoleController::store()` / `update()`。
 *
 * 与 `PermissionRequest` 同理，一个类服务两种操作，用 `$this->route('role')` 区分必填与否。
 *
 * ⚠️ `tone` 用 `Rule::in` 而不是 `Rule::enum`：后者的中文提示没进我们的语言包，
 *    会漏出英文原文。`in` 是通用规则，语言包里已经有中文。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/08-rbac.md
 */

namespace App\Modules\Rbac\Http\Requests;

use App\Modules\Rbac\Enums\RoleTone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RoleRequest extends FormRequest
{
    /**
     * 判断调用者是否有权发这个请求。
     *
     * 边界/注意：
     *   恒为 true —— 鉴权由路由上的 `auth:admin` 表达。
     *
     * @return bool 恒为 true
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    接鉴权中间件后改成 `$this->user('admin')->can('system.role')`
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 入参规则。
     *
     * 用法：
     *   POST   /api/v1/admin/roles       { "name": "论坛运营", "tone": "cyan", "permissions": ["forum.read"] }
     *   PATCH  /api/v1/admin/roles/{id}  { "permissions": ["forum.read", "forum.post.pin"] }
     *
     * 边界/注意：
     *   `permissions` 传的是**权限点的 key 列表**（前端就是按下标算的），
     *   传了就是**全量替换**；不传表示"不动基线"。
     *
     * @return array<string, array<int, mixed>> 规则数组
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function rules(): array
    {
        $presence = $this->route('role') === null ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:255'],
            'tone' => ['nullable', Rule::in(array_column(RoleTone::cases(), 'value'))],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:64'],
        ];
    }
}
