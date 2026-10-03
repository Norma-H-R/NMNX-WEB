import { reactive } from 'vue'
import { derivePublicId } from '@/utils/publicId'
import { findRole } from '@/mock/roles'
import {
  type PermissionKey,
  type UserActivity,
  type UserDetail,
  type UserLicense,
  type UserLicenseStatus,
  type UserRecord,
  type UserRole,
  type UserStatus,
} from '@/types/user'

/**
 * 用户模块的本地假数据。
 *
 * 后端（core）的用户表还没建，这里先用假数据把界面跑起来，
 * 接接口时把 listUsers / getUserDetail 换成 fetch 就行 —— 页面本身不用改。
 *
 * 数据放在**模块级 reactive** 里，而不是每次调用返回一份新数组：
 * 这样在页面上改了某个人的权限、切到别的模块再回来，改动还在 ——
 * 和"后端已经存下来了"的行为一致。等接了真接口，这里换成 pinia 或直接走 HTTP。
 *
 * 公开 ID 用 derivePublicId 从自增 id 推出来，模拟"注册时后端分配"的结果。
 * 正式环境这个值来自后端，前端不参与生成。
 */

type Seed = {
  id: number
  nickname: string
  email: string
  role: UserRole
  status?: UserStatus
  /** 覆盖角色默认权限（不传就用角色的默认权限） */
  permissions?: PermissionKey[]
  registeredAt: string
  lastSeenAt: string
}

const SEEDS: Seed[] = [
  {
    id: 1,
    nickname: '南门小星',
    email: 'root@nmnx.io',
    role: 'owner',
    registeredAt: '2024-03-02 09:12',
    lastSeenAt: '2026-10-03 14:02',
  },
  {
    id: 2,
    nickname: '清风徐来',
    email: 'qingfeng@nmnx.io',
    role: 'admin',
    permissions: [
      'user.read',
      'user.detail',
      'user.edit',
      'user.status',
      'notice.read',
      'notice.create',
      'audit.read',
    ],
    registeredAt: '2024-04-18 15:40',
    lastSeenAt: '2026-10-03 11:26',
  },
  {
    id: 3,
    nickname: '阿彻',
    email: 'ache@nmnx.io',
    role: 'admin',
    permissions: [
      'user.read',
      'user.detail',
      'product.read',
      'product.edit',
      'license.read',
      'license.extend',
      'article.read',
      'article.create',
    ],
    registeredAt: '2024-06-01 10:05',
    lastSeenAt: '2026-10-02 22:48',
  },
  {
    id: 4,
    nickname: '夜航船',
    email: 'yehang@outlook.com',
    role: 'moderator',
    registeredAt: '2024-07-11 20:31',
    lastSeenAt: '2026-10-03 09:15',
  },
  {
    id: 5,
    nickname: '麦田守望',
    email: 'maitian@qq.com',
    role: 'moderator',
    registeredAt: '2024-08-24 08:47',
    lastSeenAt: '2026-10-01 19:03',
  },
  {
    id: 6,
    nickname: '量化老张',
    email: 'laozhang@163.com',
    role: 'blogger',
    registeredAt: '2024-09-05 13:22',
    lastSeenAt: '2026-10-03 08:40',
  },
  {
    id: 7,
    nickname: '拾光',
    email: 'shiguang@gmail.com',
    role: 'blogger',
    registeredAt: '2024-10-19 21:09',
    lastSeenAt: '2026-09-29 16:55',
  },
  {
    id: 8,
    nickname: 'Nova',
    email: 'nova@proton.me',
    role: 'blogger',
    registeredAt: '2025-01-08 11:33',
    lastSeenAt: '2026-10-02 13:12',
  },
  {
    id: 9,
    nickname: '白露',
    email: 'bailu@126.com',
    role: 'blogger',
    status: 'muted',
    registeredAt: '2025-02-14 09:58',
    lastSeenAt: '2026-09-18 10:20',
  },
  {
    id: 10,
    nickname: '星辰大海',
    email: 'xingchen@sina.com',
    role: 'member',
    registeredAt: '2025-03-27 17:44',
    lastSeenAt: '2026-10-03 07:31',
  },
  {
    id: 11,
    nickname: 'Alpha猫',
    email: 'alphacat@gmail.com',
    role: 'member',
    registeredAt: '2025-04-30 14:16',
    lastSeenAt: '2026-09-30 23:07',
  },
  {
    id: 12,
    nickname: '山楂树',
    email: 'shanzha@qq.com',
    role: 'member',
    registeredAt: '2025-05-22 10:52',
    lastSeenAt: '2026-10-01 12:39',
  },
  {
    id: 13,
    nickname: '子夜',
    email: 'ziye@foxmail.com',
    role: 'member',
    status: 'banned',
    registeredAt: '2025-06-09 03:27',
    lastSeenAt: '2026-07-14 18:22',
  },
  {
    id: 14,
    nickname: 'Leo',
    email: 'leo@nmnx.io',
    role: 'member',
    registeredAt: '2025-07-15 16:08',
    lastSeenAt: '2026-10-02 20:44',
  },
  {
    id: 15,
    nickname: '老陈量化',
    email: 'laochen@163.com',
    role: 'member',
    registeredAt: '2025-08-02 19:35',
    lastSeenAt: '2026-10-03 10:11',
  },
  {
    id: 16,
    nickname: '米粒',
    email: 'mili@gmail.com',
    role: 'member',
    registeredAt: '2025-09-19 12:01',
    lastSeenAt: '2026-09-26 09:33',
  },
  {
    id: 17,
    nickname: '铁匠',
    email: 'tiejiang@qq.com',
    role: 'member',
    registeredAt: '2025-11-06 15:49',
    lastSeenAt: '2026-09-12 21:58',
  },
  {
    id: 18,
    nickname: 'Kite',
    email: 'kite@proton.me',
    role: 'member',
    registeredAt: '2026-01-23 08:14',
    lastSeenAt: '2026-10-03 13:47',
  },
]

/** 模块级状态：页面上改的权限会保留，切模块回来还在 */
export const mockUsers: UserRecord[] = reactive(
  SEEDS.map((seed) => ({
    id: seed.id,
    publicId: derivePublicId(seed.id),
    nickname: seed.nickname,
    email: seed.email,
    role: seed.role,
    status: seed.status ?? 'active',
    // 没显式指定权限的，用它的角色基线（owner 隐含全部，这里留空）
    permissions: seed.permissions ?? [...(findRole(seed.role)?.permissions ?? [])],
    registeredAt: seed.registeredAt,
    lastSeenAt: seed.lastSeenAt,
  })),
)

/**
 * 身份权限模板（roleTemplates）已经搬到 mock/permissions.ts ——
 * 它属于"权限"域，和权限点清单放在一起才不会出现
 * "删了权限点、模板里还留着引用"这种半拉子状态。
 */

export function listUsers(): UserRecord[] {
  return mockUsers
}

/** 只拿管理员（含超级管理员）—— 管理员设置页用 */
export function listAdmins(): UserRecord[] {
  return mockUsers.filter((user) => user.role === 'owner' || user.role === 'admin')
}

// ── 用户详情 ──────────────────────────────────────────────────────────

const PRODUCTS = [
  { product: 'NMNX Master', edition: '机构版' },
  { product: 'NMNX Master', edition: '专业版' },
  { product: 'NMNX Follower', edition: '标准版' },
]

/**
 * 派生授权状态。
 *
 * 抽成函数而不是写内联三元：写内联时 TS 会把那个 const 窄化成字面量联合
 * （'revoked' | 'expiring' | 'active'），后面再拿它跟 'expired' 比就变成
 * "恒为假"，直接报 TS2367。函数返回值的声明类型不会被这么窄化。
 */
function pickLicenseStatus(user: UserRecord): UserLicenseStatus {
  if (user.status === 'banned') return 'revoked'
  if (user.id % 6 === 0) return 'expiring'
  if (user.id % 7 === 0) return 'expired'
  return 'active'
}

/**
 * 生成某个用户名下的授权与近期动态。
 * 用 id 做确定性派生（而不是随机），保证同一个用户每次点开看到的一样 ——
 * 否则每次打开详情数据都在变，没法核对问题。
 */
function buildDetail(user: UserRecord): UserDetail {
  const licenses: UserLicense[] = []

  // 管理团队不挂授权；普通用户与博主里每隔一个挂一张，模拟真实分布
  if (user.role === 'member' || user.role === 'blogger') {
    if (user.id % 2 === 0) {
      const preset = PRODUCTS[user.id % PRODUCTS.length] ?? PRODUCTS[0]!
      const status = pickLicenseStatus(user)

      licenses.push({
        code: `NMX-L-${String(user.id).padStart(4, '0')}-${(user.id * 7919) % 9000 + 1000}`,
        product: preset.product,
        edition: preset.edition,
        status,
        machineId: `${(user.id * 104729).toString(16).toUpperCase().slice(0, 12).padEnd(12, '0')}`,
        boundAt: user.registeredAt.slice(0, 10),
        expireAt: status === 'expired' ? '2025-12-31' : '2027-03-01',
      })
    }
  }

  const activity: UserActivity[] = [
    {
      id: 1,
      kind: 'login',
      title: '登录成功',
      detail: 'Web 端 · 中国大陆',
      at: user.lastSeenAt,
      ip: `112.${user.id * 3 % 255}.${user.id * 7 % 255}.${user.id * 11 % 255}`,
    },
  ]

  if (licenses.length) {
    const license = licenses[0]!
    activity.push({
      id: 2,
      kind: 'license',
      title: '授权校验通过',
      detail: `${license.product} · ${license.code}`,
      at: '2026-10-03 06:12',
      ip: `112.${user.id * 3 % 255}.${user.id * 7 % 255}.18`,
    })
  }

  if (user.role === 'blogger' || user.role === 'member') {
    activity.push({
      id: 3,
      kind: 'post',
      title: user.role === 'blogger' ? '提交博客投稿' : '在论坛发布主题',
      detail: user.role === 'blogger' ? '《用 Python 复现一套日内动量策略》' : '【求助】MT5 多实例跟随的延迟问题',
      at: '2026-10-02 21:36',
      ip: `112.${user.id * 3 % 255}.${user.id * 5 % 255}.42`,
    })
  }

  if (user.role === 'owner' || user.role === 'admin') {
    activity.push({
      id: 4,
      kind: 'admin',
      title: '修改了用户权限',
      detail: '目标：拾光（NMX-U-000007）',
      at: '2026-10-02 17:20',
      ip: '10.0.0.12',
    })
  }

  if (user.status !== 'active') {
    activity.push({
      id: 5,
      kind: 'risk',
      title: user.status === 'banned' ? '账号被永久封禁' : '账号被禁言',
      detail: '原因：多次发布违规内容',
      at: '2026-07-14 18:20',
      ip: '10.0.0.12',
    })
  }

  return {
    ...user,
    phone: user.id % 3 === 0 ? undefined : `13${String(user.id).padStart(2, '0')}****${String(user.id * 37 % 10000).padStart(4, '0')}`,
    bio:
      user.role === 'blogger'
        ? '专注量化策略与执行链路优化，写点踩坑记录。'
        : user.role === 'member'
          ? '用 MT5 跑跟随端，关注滑点与延迟。'
          : undefined,
    licenses,
    activity,
  }
}

export function getUserDetail(id: number): UserDetail | null {
  const user = mockUsers.find((item) => item.id === id)
  return user ? buildDetail(user) : null
}

// ── 权限点的引用清理 ──────────────────────────────────────────────────

/**
 * 有多少个用户的权限里包含这个权限点。
 * 删除权限点前要提示影面范围 —— 悄悄删掉一个在用的权限，
 * 会让某些管理员下次登录时"莫名其妙少了几个功能"。
 */
export function countUsersWithPermission(key: PermissionKey): number {
  return mockUsers.filter((user) => user.permissions.includes(key)).length
}

/** 从所有用户的权限里摘掉某个权限点（删除权限点时调用） */
export function purgePermissionFromUsers(key: PermissionKey) {
  for (const user of mockUsers) {
    user.permissions = user.permissions.filter((item) => item !== key)
  }
}

/** 权限点改名后同步用户权限里的引用（和 renamePermissionInRoles 配套使用） */
export function renamePermissionRefs(oldKey: PermissionKey, newKey: PermissionKey) {
  for (const user of mockUsers) {
    user.permissions = user.permissions.map((item) => (item === oldKey ? newKey : item))
  }
}

// ── 角色相关的用户操作 ────────────────────────────────────────────────

/** 有多少用户挂在这个角色下（删除角色前提示用） */
export function countUsersWithRole(key: UserRole): number {
  return mockUsers.filter((user) => user.role === key).length
}

/**
 * 角色被删除后，把它下面的用户转成普通用户。
 *
 * 必须转移：不转的话用户的 role 会指向一个不存在的角色 ——
 * 界面上显示"未知身份"，鉴权时可能因为查不到角色而放行或全拒，两种都是坑。
 * 顺带把权限也重置成新角色的基线，避免留下前一个角色的残留权限。
 */
export function migrateRoleUsers(fromKey: UserRole, toKey: UserRole = 'member'): number {
  const affected = mockUsers.filter((user) => user.role === fromKey)
  for (const user of affected) {
    user.role = toKey
    user.permissions = [...(findRole(toKey)?.permissions ?? [])]
  }
  return affected.length
}
