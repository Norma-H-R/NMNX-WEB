/**
 * 角色（身份）—— **从 core（PHP）读回来**，不再是本地假数据
 *
 * 大改之前这套在 `mock/roles.ts` 里写死 5 个角色，页面上的角色、默认权限基线、
 * 甚至"N 人"都是假的 —— 与数据库里的 `roles` / `role_permissions` **毫无关系**。
 * 现在全部走 `GET /api/v1/admin/roles`，**数据库是唯一权威**。
 *
 * 与权限目录（`api/rbac.ts`）同一套做法：写操作直接打接口，成功后**整体重拉**，
 * 不做"本地改一改再猜服务端状态"。
 *
 * 两个刻意的设计：
 *   1. 对外仍以**角色的 key** 操作（`updateRole('moderator', …)`）——
 *      调用方（老页面）习惯这样；内部自己翻译成后端要的 `id`。
 *      ⚠️ 但路由参数必须是 `id`：key 允许改名，一改 URL 就变了。
 *   2. 写操作**失败就抛 `ApiError`**（不做 `string | null` 那套返回值）——
 *      后端给的 message 已经是人话，调用方 `try/catch` 直接显示，最省事。
 *
 * @version 0.1.0
 * @since 2026-10-03
 */

import { reactive } from 'vue'
import { apiRequest } from './client'
import type { PermissionKey, RoleRecord, ToneName } from '@/types/user'

/** 角色清单（从后端读回来的，按后端给的顺序） */
export const roles = reactive<RoleRecord[]>([])

/**
 * 可选的徽章配色。
 *
 * ⚠️ 这是**展示层**的固定枚举，与后端的 `RoleTone` 一一对应。
 * 之所以不在前端也拉一次后端选项：配色是纯展示的事，且集合固定；
 * 后端会在写入时用 `Rule::in` 校验，传错值会被拒。
 */
export const ROLE_TONES: ToneName[] = ['cyan', 'violet', 'green', 'gold', 'red', 'slate']

/**
 * 拉一次角色清单。
 *
 * 用法：
 *   await loadRoles()
 *
 * 边界/注意：
 *   会**原地替换** `roles` 的内容（换数组会让模板里的引用失效）。
 */
export async function loadRoles(): Promise<void> {
  const data = await apiRequest<{ roles: RoleRecord[] }>('/api/v1/admin/roles')

  roles.splice(0, roles.length, ...data.roles)
}

/**
 * 按 key 找角色。
 *
 * @returns 找不到返回 null（渲染兜底用，不要抛）
 */
export function findRole(key: string): RoleRecord | null {
  return roles.find((role) => role.key === key) ?? null
}

/** 角色显示名（拿不到时给个兜底，避免页面因脏数据整块崩掉） */
export function roleNameOf(key: string): string {
  return findRole(key)?.name ?? '未知身份'
}

/** 角色的徽章配色（拿不到时给灰色） */
export function roleToneOf(key: string): ToneName {
  return findRole(key)?.tone ?? 'slate'
}

/** 新建 / 修改角色的入参 */
export interface RoleDraft {
  name: string
  desc: string
  tone: ToneName
  permissions: PermissionKey[]
}

/**
 * 新建角色。
 *
 * 用法：
 *   const created = await createRole({ name: '论坛运营', desc: '', tone: 'cyan', permissions: [] })
 *
 * 边界/注意：
 *   **key 由后端生成**（`custom-1`、`custom-2`…），调用方不用管。
 *
 * @returns 新建的角色（已重新拉取过清单，`roles` 里能查到它）
 */
export async function createRole(draft: RoleDraft): Promise<RoleRecord> {
  const data = await apiRequest<{ role: RoleRecord }>('/api/v1/admin/roles', {
    method: 'POST',
    body: {
      name: draft.name,
      description: draft.desc,
      tone: draft.tone,
      permissions: draft.permissions,
    },
  })

  await loadRoles()

  return data.role
}

/**
 * 修改角色（名字 / 说明 / 配色 / 默认权限基线）。
 *
 * 边界/注意：
 *   传了 `permissions` 就是**全量替换**基线（后端 `sync`），不是追加。
 *   `owner` 隐含全部权限，后端会拒绝给它配基线。
 *
 * @throws 角色不存在时抛 `Error`（正常流程不该发生）
 */
export async function updateRole(key: string, patch: Partial<RoleDraft>): Promise<RoleRecord> {
  const role = findRole(key)

  if (!role) {
    throw new Error('角色不存在，请刷新页面')
  }

  const data = await apiRequest<{ role: RoleRecord }>(`/api/v1/admin/roles/${role.id}`, {
    method: 'PATCH',
    body: {
      name: patch.name,
      description: patch.desc,
      tone: patch.tone,
      permissions: patch.permissions,
    },
  })

  await loadRoles()

  return data.role
}

/**
 * 删除角色。
 *
 * 边界/注意：
 *   挂在这个角色下的用户由**后端**先转成 `member` 再删（顺序不能反：
 *   用户总表的外键是 restrictOnDelete，还有人用时数据库会拒绝删）。
 *   所以前端**不需要**再做任何"迁移用户"的动作。
 *
 * @returns 被转为普通用户的用户数（后端返回，用于提示）
 */
export async function deleteRole(key: string): Promise<number> {
  const role = findRole(key)

  if (!role) {
    throw new Error('角色不存在，请刷新页面')
  }

  const data = await apiRequest<{ moved_users: number }>(`/api/v1/admin/roles/${role.id}`, {
    method: 'DELETE',
  })

  await loadRoles()

  return data.moved_users ?? 0
}
