/**
 * 「我有哪些权限」—— 前端只拿它做**显隐**，不是安全边界
 *
 * ⚠️ 再说一遍（因为很重要）：这份列表可以让界面"少画一个按钮"，
 *    **但只要没画住，后端照样拦**。安全边界在 PHP 的 `permission:` 中间件，
 *    服务端从不读前端声明的权限。
 *
 * 三条刷新时机（缺一就会出现"按钮还在但一点就炸"）：
 *   1. 登录成功后（登录响应里就带着，见 `LoginView`）；
 *   2. 刷新页面后（有令牌但内存里没列表 → `main.ts` 启动时拉一次）；
 *   3. **收到 403 之后**（权限可能刚被管理员收走 → `main.ts` 监听 `nmnx:forbidden` 重拉）。
 *
 * @version 0.1.0
 * @since 2026-10-04
 */

import { readonly, ref } from 'vue'
import { apiRequest } from './client'

/** 我自己的权限 key 列表（只读，外部不要改） */
const permissions = ref<string[]>([])

/** 对外暴露的只读权限列表（模板里直接用） */
export const myPermissions = readonly(permissions)

/**
 * 重新拉取"我自己的权限"。
 *
 * 用法：
 *   await loadMyPermissions()
 *
 * 边界/注意：
 *   没登录时会 401 —— 调用方自己不要的那种情况下别调（`main.ts` 已经判过令牌）。
 */
export async function loadMyPermissions(): Promise<void> {
  const data = await apiRequest<{ permissions: string[] }>('/api/v1/admin/me/permissions')

  permissions.value = data.permissions ?? []
}

/**
 * 直接写入权限列表（登录响应里已经带回来时用，省一次请求）。
 *
 * @param keys 权限 key 列表
 */
export function setMyPermissions(keys: string[]): void {
  permissions.value = [...keys]
}

/** 清空（登出时用，避免下一个登录的人看到上一个人的按钮） */
export function clearMyPermissions(): void {
  permissions.value = []
}

/**
 * 判断"我有没有某个权限"——**只用于控显隐**。
 *
 * 用法：
 *   <n-button v-if="hasPermission('user.delete')">删除</n-button>
 *
 * 边界/注意：
 *   这是**前端体验判断**。就算它返回 true，也不能因此就省掉后端的 `permission:`；
 *   反过来它返回 false 时后端也未必拒绝（可能只是列表还没刷新）。
 */
export function hasPermission(key: string): boolean {
  return permissions.value.includes(key)
}
