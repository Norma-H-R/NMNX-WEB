/**
 * 权限目录 —— **兼容层**，真实数据来自 core（PHP）后端
 *
 * 这个文件以前写死了 98 条权限点，页面永远看不到数据库里的目录
 * （core 里现在是 14 组 / 121 条）。现在它只是把 `@/api/rbac` 的东西转出去，
 * **本地不再存任何权限数据**。
 *
 * 为什么要留这么一层而不是直接删掉：
 *   还有几个老页面从 `@/mock/permissions` 引数据
 *   （`PermissionPicker`、`UserDetailDrawer`、`RolePermissionPanel`）。
 *   转出去它们**不用改 import 就自动换成数据库的数据**，
 *   否则删掉文件的瞬间整个后台都编译不过。
 *
 * ⚠️ `allPermissionKeys` 在这里是**函数**，但 `@/api/rbac` 里是数组：
 *   旧 mock 导出的就是函数，老调用方写的是 `allPermissionKeys()`。
 *   留个同形的函数比改三处调用点稳妥；等这些 import 都迁到
 *   `@/api/rbac` 之后，这个文件可以整份删掉。
 *
 * TODO 把那几处 import 改成直接从 `@/api/rbac` 引，然后删掉本文件。
 *
 * @version 0.2.0
 * @since 2026-10-03
 */

import { allPermissionKeys as apiPermissionKeys } from '@/api/rbac'

export { loadPermissions, permissionGroups } from '@/api/rbac'

export type { PermissionGroup, PermissionItem } from '@/api/rbac'

/**
 * 全部权限点的 key。
 *
 * 用法：
 *   allPermissionKeys()   // 注意：是**函数**，与 `@/api/rbac` 里的同名数组不同
 *
 * 边界/注意：
 *   返回的就是那个响应式数组本身，所以在 computed / 模板里调用它**依然会跟着更新**。
 */
export function allPermissionKeys(): string[] {
  return apiPermissionKeys
}
