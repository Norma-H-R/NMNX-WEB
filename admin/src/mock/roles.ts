/**
 * 角色（身份）—— **兼容层**，真实数据来自 core（PHP）后端
 *
 * 这个文件以前写死 5 个角色，连"这个角色有几人人"都是假的 ——
 * 页面上的角色和数据库里的 `roles` / `role_permissions` **毫无关系**。
 * 现在它只是把 `@/api/roles` 的东西转出去，**本地不再存任何角色数据**。
 *
 * 为什么要留这么一层而不是直接删掉：
 *   还有几个老页面从 `@/mock/roles` 引数据
 *   （`UserView`、`SystemAdminsView`、`UserDetailDrawer`、`RoleTag`、`mock/users.ts`）。
 *   转出去它们**不用改 import 就自动换成数据库的数据**，
 *   否则删掉文件的瞬间整个后台都编译不过。
 *
 * ⚠️ 这里**不再导出** `addRole` / `updateRole` / `removeRole`：
 *    写入一律走 `@/api/roles` 的 `createRole` / `updateRole` / `deleteRole`
 *    （它们是异步的、失败抛 `ApiError`，和旧 mock 的同步返回值语义完全不同，
 *    硬保留同名会让人误用）。原来唯一的调用方 `RolePermissionPanel` 已经改过去了。
 *
 * TODO 把剩下这几处 import 也迁到 `@/api/roles`，然后删掉本文件。
 *
 * @version 0.2.0
 * @since 2026-10-03
 */

export { findRole, loadRoles, ROLE_TONES, roles, roleNameOf, roleToneOf } from '@/api/roles'

export type { RoleDraft } from '@/api/roles'
