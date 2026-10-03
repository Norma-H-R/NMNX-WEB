/**
 * 权限目录 —— **从 core（PHP）读回来**，不再是本地假数据
 *
 * 背景：原先这套数据在 `mock/permissions.ts` 里写死 98 条，页面永远看不到
 * core 数据库里的真实目录（现在是 121 条）。现在改成从
 * `GET /api/v1/admin/permissions` 读，**数据库是唯一权威**。
 *
 * 写操作（增 / 改 / 删）都直接打接口，成功后**整体重新拉一次目录** ——
 * 不做"本地改一改再猜服务端状态"。理由：权限目录的排序、分组、自定义标记
 * 都由后端算，本地拼容易和后端不一致；目录也就 121 条，重拉的代价可以忽略。
 *
 * 一个重要简化（别去抄旧 mock 的那套）：
 *   旧 mock 里改名要"同步所有引用"（`renamePermissionRefs` 等）。后端
 *   **不需要** —— 角色基线与个人增减存的是权限点的**数字 ID**，改 key 天然安全。
 *   所以这里没有、也不该有那些同步函数。
 *
 * @version 0.1.0
 * @since 2026-10-03
 */

import { computed, reactive } from 'vue'
import { ApiError, apiRequest } from './client'

/** 权限点（与 core 的 PermissionController::payload 逐字对齐） */
export interface PermissionItem {
  /** 后端主键。**改 / 删都用它**，不要用 key —— key 允许改名 */
  id: number
  key: string
  label: string
  desc: string
  /** 界面上新增的（相对内置的） */
  custom: boolean
}

/** 一个分组 = 标题 + 该组下的权限点 */
export interface PermissionGroup {
  title: string
  items: PermissionItem[]
}

export interface PermissionDraft {
  key: string
  label: string
  desc: string
  groupTitle: string
}

/** 权限目录（按分组的顺序即后端给的顺序） */
export const permissionGroups = reactive<PermissionGroup[]>([])

/** 全部权限点的 key（做"是否有这个标识"这类判断用） */
export const allPermissionKeys = reactive<string[]>([])

/** 可选的**分组标题**（新增权限点时选分组用；不允许顺手造新分组） */
export const groupTitles = computed<string[]>(() => permissionGroups.map((group) => group.title))

/**
 * 拉一次权限目录。
 *
 * 用法：
 *   await loadPermissions()
 *
 * 边界/注意：
 *   会**原地替换** `permissionGroups` 的内容（不清空数组本身），
 *   这样模板里对它的引用不会因为换了一个数组而失效。
 */
export async function loadPermissions(): Promise<void> {
  const data = await apiRequest<{ groups: PermissionGroup[], keys: string[] }>('/api/v1/admin/permissions')

  permissionGroups.splice(0, permissionGroups.length, ...data.groups)
  allPermissionKeys.splice(0, allPermissionKeys.length, ...data.keys)
}

/**
 * 新增权限点。
 *
 * @returns 成功返回 null，失败返回**可直接显示**的原因（后端给的中文提示）
 */
export async function addPermission(draft: PermissionDraft): Promise<string | null> {
  try {
    await apiRequest('/api/v1/admin/permissions', {
      method: 'POST',
      body: {
        key: draft.key,
        label: draft.label,
        description: draft.desc,
        group_title: draft.groupTitle,
      },
    })
  } catch (error) {
    return error instanceof ApiError ? error.message : '新增失败'
  }

  await loadPermissions()

  return null
}

/**
 * 修改权限点。
 *
 * 边界/注意：
 *   `id` 是权限点的后端主键（不是 key）。改 key 之后 `id` 不变 ——
 *   这正是"引用不需要同步"的原因。
 */
export async function updatePermission(
  id: number,
  patch: { key: string, label: string, desc: string },
): Promise<string | null> {
  try {
    await apiRequest(`/api/v1/admin/permissions/${id}`, {
      method: 'PATCH',
      body: { key: patch.key, label: patch.label, description: patch.desc },
    })
  } catch (error) {
    return error instanceof ApiError ? error.message : '保存失败'
  }

  await loadPermissions()

  return null
}

/**
 * 删除权限点。
 *
 * 边界/注意：
 *   角色基线与个人增减里的引用由后端**外键级联**清掉，前端不需要做任何清理。
 */
export async function removePermission(id: number): Promise<string | null> {
  try {
    await apiRequest(`/api/v1/admin/permissions/${id}`, { method: 'DELETE' })
  } catch (error) {
    return error instanceof ApiError ? error.message : '删除失败'
  }

  await loadPermissions()

  return null
}
