<?php

declare(strict_types=1);

/**
 * RoleController —— 角色的读写入口
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块。
 * 用途：**只做 HTTP 翻译**。读走预加载层，写走 RbacService（写完 flush 缓存）。
 * 谁在调：`routes/api.php` 里 `/api/v1/admin/roles*` 四条路由。
 *
 * 为什么把 `tones` 一起吐出来：
 *   角色要选配色，而"有哪些可选配色"是**后端枚举的能力**（`RoleTone`）。
 *   让前端各写一份颜色键列表，早晚会对不上。这里直接给它一份选项。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/08-rbac.md
 */

namespace App\Modules\Rbac\Http\Controllers;

use App\Modules\Rbac\Enums\RoleTone;
use App\Modules\Rbac\Http\Requests\RoleRequest;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Services\PermissionRegistry;
use App\Modules\Rbac\Services\RbacService;
use App\Modules\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

final class RoleController
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
     * 角色列表（含各自的默认权限基线）。
     *
     * 用法：
     *   GET /api/v1/admin/roles
     *   200 → data: {
     *     "roles": [ { "id":2, "key":"admin", "name":"管理员", "desc":"…", "tone":"cyan",
     *                  "tone_label":"青", "builtin":true, "locked":false, "grants_all":false,
     *                  "permissions":["user.read", …] } ],
     *     "tones": [ { "value":"cyan", "label":"青" }, … ]
     *   }
     *
     * 边界/注意：
     *   `grants_all` 的角色（owner）其 `permissions` **已展开成全部 key**，调用方不用再判。
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
            'roles' => $this->registry->roles(),
            'tones' => RoleTone::options(),
        ]);
    }

    /**
     * 新增角色。
     *
     * 用法：
     *   POST /api/v1/admin/roles
     *   { "name": "论坛运营", "description": "…", "tone": "cyan", "permissions": ["forum.read"] }
     *
     *   200 → data: { "role": { … } }
     *
     * 边界/注意：
     *   `key` **由后端生成**（`custom-1`…），调用方不要传 —— 传了也会被忽略。
     *
     * @param  RoleRequest  $request  已校验的入参
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function store(RoleRequest $request): JsonResponse
    {
        $role = $this->service->createRole([
            'name' => (string) $request->string('name'),
            'description' => (string) $request->string('description'),
            'tone' => (string) $request->string('tone'),
        ]);

        if ($request->has('permissions')) {
            $role = $this->service->updateRole($role, [
                'permissions' => array_values((array) $request->input('permissions', [])),
            ]);
        }

        return ApiResponse::ok(['role' => $this->payload($role)]);
    }

    /**
     * 修改角色（名字 / 说明 / 配色 / 权限基线）。
     *
     * 用法：
     *   PATCH /api/v1/admin/roles/3
     *   { "permissions": ["forum.read", "forum.post.pin"] }   ← 全量替换基线
     *
     *   422 → SYS_VALIDATION（基线里有不存在的权限点 / 想改 owner 的基线）
     *
     * @param  RoleRequest  $request  已校验的入参
     * @param  Role  $role  路由模型绑定的目标角色
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function update(RoleRequest $request, Role $role): JsonResponse
    {
        $data = [];

        foreach (['name', 'description', 'tone'] as $field) {
            if ($request->has($field)) {
                $data[$field] = (string) $request->string($field);
            }
        }

        if ($request->has('permissions')) {
            $data['permissions'] = array_values((array) $request->input('permissions', []));
        }

        return ApiResponse::ok(['role' => $this->payload($this->service->updateRole($role, $data))]);
    }

    /**
     * 删除角色。
     *
     * 用法：
     *   DELETE /api/v1/admin/roles/3
     *   200 → data: { "moved_users": 2 }
     *
     * 边界/注意：
     *   挂在这个角色下的用户会**先被转成 member**，再删角色（顺序不能反，
     *   用户总表的外键是 restrictOnDelete，还有人用时数据库会拒绝）。
     *   出参把转移人数吐出来，让前端能提示"已把 N 个用户转为普通用户"。
     *
     * @param  Role  $role  目标角色
     * @return JsonResponse 统一响应体
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function destroy(Role $role): JsonResponse
    {
        $moved = $this->service->deleteRole($role);

        return ApiResponse::ok(['moved_users' => $moved]);
    }

    /**
     * 单个角色的出参形状（与列表里的条目逐字一致）。
     *
     * @param  Role  $role  角色
     * @return array<string, mixed> 出参
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    private function payload(Role $role): array
    {
        foreach ($this->registry->roles() as $item) {
            if ($item['id'] === $role->getKey()) {
                return $item;
            }
        }

        // 兜底：理论上走不到（service 写完会 flush，registry 里一定有它）
        return [
            'id' => $role->getKey(),
            'key' => $role->key,
            'name' => $role->name,
            'desc' => $role->description,
            'tone' => $role->tone->value,
            'tone_label' => $role->tone->label(),
            'builtin' => $role->is_builtin,
            'locked' => $role->is_locked,
            'grants_all' => $role->grants_all,
            'permissions' => $role->permissions()->pluck('key')->all(),
        ];
    }
}
