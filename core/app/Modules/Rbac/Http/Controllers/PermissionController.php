<?php

declare(strict_types=1);

/**
 * PermissionController —— 权限点目录的读写入口
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块。
 * 用途：**只做 HTTP 翻译** —— 取参 → 交给 Request 校验 → 调 Service → 用 ApiResponse 出参。
 *       读走预加载层（不查业务表），写走 RbacService（写完会 flush 缓存）。
 * 谁在调：`routes/api.php` 里 `/api/v1/admin/permissions*` 四条路由。
 *
 * ⚠️ 出参里的 `id` 是**必须**的：前端要靠它做 `PATCH /permissions/{id}`。
 *    不能用 `key` 当路由参数 —— key 允许改名，一改 URL 就变了。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/08-rbac.md
 */

namespace App\Modules\Rbac\Http\Controllers;

use App\Modules\Rbac\Http\Requests\PermissionRequest;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Services\PermissionRegistry;
use App\Modules\Rbac\Services\RbacService;
use App\Modules\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

final class PermissionController
{
    /**
     * 构造：注入预加载层与写服务。
     *
     * @param  PermissionRegistry  $registry  预加载层（读）
     * @param  RbacService  $service  写操作
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly RbacService $service,
    ) {}

    /**
     * 权限目录（按分组组织）。
     *
     * 用法：
     *   GET /api/v1/admin/permissions
     *   200 → data: {
     *     "groups": [ { "title": "用户管理", "items": [ { "id":1, "key":"user.read", "label":"查看用户", "desc":"…", "custom":false } ] } ],
     *     "keys": ["user.read", …]
     *   }
     *
     * 边界/注意：
     *   数据来自预加载层（请求内记忆 + 缓存），**不查业务表**。
     *
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function index(): JsonResponse
    {
        return ApiResponse::ok([
            'groups' => $this->registry->catalog(),
            'keys' => $this->registry->allKeys(),
        ]);
    }

    /**
     * 新增权限点。
     *
     * 用法：
     *   POST /api/v1/admin/permissions
     *   { "key": "media.alert", "label": "告警规则", "description": "…", "group_title": "自媒体" }
     *
     *   200 → data: { "permission": { … } }
     *   422 → SYS_VALIDATION（标识格式不对 / 重复 / 分组不存在）
     *
     * @param  PermissionRequest  $request  已校验的入参
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function store(PermissionRequest $request): JsonResponse
    {
        $permission = $this->service->createPermission([
            'key' => (string) $request->string('key'),
            'label' => (string) $request->string('label'),
            'description' => (string) $request->string('description'),
            'group_title' => (string) $request->string('group_title'),
        ]);

        return ApiResponse::ok(['permission' => self::payload($permission)]);
    }

    /**
     * 修改权限点（改 key 也是安全的，引用不需要同步）。
     *
     * 用法：
     *   PATCH /api/v1/admin/permissions/12
     *   { "label": "新名字" }         ← 只传要改的字段
     *
     * @param  PermissionRequest  $request  已校验的入参
     * @param  Permission  $permission  路由模型绑定的目标权限点
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function update(PermissionRequest $request, Permission $permission): JsonResponse
    {
        $data = [];

        foreach (['key', 'label', 'description'] as $field) {
            if ($request->has($field)) {
                $data[$field] = (string) $request->string($field);
            }
        }

        return ApiResponse::ok([
            'permission' => self::payload($this->service->updatePermission($permission, $data)),
        ]);
    }

    /**
     * 删除权限点。
     *
     * 用法：
     *   DELETE /api/v1/admin/permissions/12
     *   200 → data: {}
     *
     * 边界/注意：
     *   角色基线与个人增减里的引用由**外键级联**一起清掉，不会留下"幽灵项"。
     *
     * @param  Permission  $permission  目标权限点
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function destroy(Permission $permission): JsonResponse
    {
        $this->service->deletePermission($permission);

        return ApiResponse::ok();
    }

    /**
     * 单个权限点的出参形状。
     *
     * 边界/注意：
     *   字段名刻意与目录里的条目**逐字一致**（`desc` 而不是 `description`）——
     *   前端拿到单个权限点后可以直接塞回列表里，不用再转换一次。
     *
     * @param  Permission  $permission  权限点
     * @return array<string, mixed> 出参
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    private static function payload(Permission $permission): array
    {
        return [
            'id' => $permission->id,
            'key' => $permission->key,
            'label' => $permission->label,
            'desc' => $permission->description,
            'custom' => ! $permission->is_builtin,
        ];
    }
}
