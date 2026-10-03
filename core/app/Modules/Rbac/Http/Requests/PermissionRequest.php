<?php

declare(strict_types=1);

/**
 * PermissionRequest —— 权限点新增 / 修改的入参校验
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块。
 * 用途：只校验**形状**（必填、类型、长度）。校验失败由全局异常处理器统一转成
 *       `SYS_VALIDATION` + `meta.errors`，所以这里不写 `failedValidation()`。
 *       业务规则（标识格式、是否重复、分组是否存在）在 `RbacService` 里 ——
 *       那里是唯一权威，接口和 Seeder 共用同一套判断。
 * 谁在调：`PermissionController::store()` / `update()`。
 *
 * 一个类同时服务"新增"和"修改"：用 `$this->route('permission')` 是否为空来区分
 * —— 新增时关键字段必填，修改时**只校验传了的字段**（`sometimes`）。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/08-rbac.md
 */

namespace App\Modules\Rbac\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PermissionRequest extends FormRequest
{
    /**
     * 判断调用者是否有权发这个请求。
     *
     * 边界/注意：
     *   恒为 true —— 鉴权由路由上的 `auth:admin` 表达（且后续要加 `can('system.role')`）。
     *   这里再判一次是重复劳动，还容易两处规则不一致。
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
     *   POST   /api/v1/admin/permissions          { "key": "media.alert", "label": "…", "group_title": "自媒体" }
     *   PATCH  /api/v1/admin/permissions/{id}     { "label": "新名字" }
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
        $presence = $this->route('permission') === null ? 'required' : 'sometimes';

        return [
            'key' => [$presence, 'string', 'max:64'],
            'label' => [$presence, 'string', 'max:64'],
            'description' => ['nullable', 'string', 'max:255'],
            'group_title' => [$presence, 'string', 'max:32'],
        ];
    }
}
