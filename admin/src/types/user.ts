/**
 * 用户 / 身份 / 权限的类型定义。
 *
 * 这是前后端的"契约草稿" —— core 的用户模块还没做，一旦定了字段名两边就要一致，
 * 所以集中放这一个文件：改契约只改这里，页面上不出现散落的字符串字面量。
 *
 * 三层结构（越往下越基础）：
 *   权限点  权限清单，可增删改            → mock/permissions.ts
 *   角色    名字 + 配色 + 默认权限，可增删改 → mock/roles.ts
 *   用户    挂一个角色 + 一份个人权限       → mock/users.ts
 */

/**
 * 角色标识。
 *
 * ⚠️ 是 string 而不是联合类型，因为**角色可以在界面上自定义**：
 * "论坛管理员 A"和"论坛管理员 B"权限不同，就得是两个角色。
 * 内置的那几个见 BuiltinRoleKey（代码里写死的地方用它做拼写检查）。
 */
export type UserRole = string

/** 内置角色。owner 与 member 是系统依赖，其余三个是初始模板 */
export type BuiltinRoleKey = 'owner' | 'admin' | 'moderator' | 'blogger' | 'member'

export type ToneName = 'gold' | 'cyan' | 'violet' | 'green' | 'slate' | 'red'

/**
 * 徽章色调。对齐官网那套调色板。
 *
 * 色值集中在下面这张表里，角色徽章和状态徽章**共用同一份** ——
 * 各写一份的话，改品牌色时必然只改一半，界面上就会有两种不同的"金色"。
 */
export const TONE_COLORS: Record<
  ToneName,
  { color: string; textColor: string; borderColor: string }
> = {
  gold: {
    color: 'rgba(242, 209, 141, 0.14)',
    textColor: '#f2d18d',
    borderColor: 'rgba(242, 209, 141, 0.3)',
  },
  cyan: {
    color: 'rgba(110, 231, 255, 0.14)',
    textColor: '#8beeff',
    borderColor: 'rgba(110, 231, 255, 0.32)',
  },
  violet: {
    color: 'rgba(167, 139, 250, 0.14)',
    textColor: '#bda6ff',
    borderColor: 'rgba(167, 139, 250, 0.32)',
  },
  green: {
    color: 'rgba(107, 226, 168, 0.14)',
    textColor: '#7fe3b4',
    borderColor: 'rgba(107, 226, 168, 0.3)',
  },
  slate: {
    color: 'rgba(255, 255, 255, 0.06)',
    textColor: '#a9b1c6',
    borderColor: 'rgba(255, 255, 255, 0.14)',
  },
  red: {
    color: 'rgba(255, 107, 107, 0.14)',
    textColor: '#ff9f9f',
    borderColor: 'rgba(255, 107, 107, 0.32)',
  },
}

/** 新建/编辑角色时可选的颜色，以及它们在界面上的中文名 */
export const TONE_LABELS: Record<ToneName, string> = {
  cyan: '青（默认）',
  violet: '紫',
  green: '绿',
  gold: '金',
  red: '红',
  slate: '灰',
}

// ── 角色 ──────────────────────────────────────────────────────────────

/**
 * 一个角色 = 一个身份。
 *
 * 它同时是两件事：
 *   1) 给用户看的标签（名字 + 配色）
 *   2) 一份默认权限基线（这个身份的人一进来默认能做什么）
 * 具体某个人在基线上还能单独加减，见 mock/users.ts 的 permissions。
 */
export type RoleRecord = {
  /**
   * 后端主键（`roles.id`）。
   *
   * ⚠️ 改 / 删的**路由参数必须是它**，不能用 `key` —— key 允许改名，一改 URL 就变了。
   */
  id: number
  key: UserRole
  /** 显示名，比如"论坛管理员 A"。用户自定义，改起来不影响任何逻辑 */
  name: string
  desc: string
  /** 徽章配色 */
  tone: ToneName
  /** 配色中文名（后端给；下拉选项直接用后端这份，别在前端再写一份） */
  tone_label?: string
  /**
   * 内置角色（种子导入的，相对界面上自定义的）。
   * owner 与 member 是系统依赖：不允许删除；owner 连权限都不允许改（隐含全部）。
   */
  builtin?: boolean
  /** 不允许删除。⚠️ 用后端算好的这个标志，**不要**在前端按 key 硬判断 */
  locked?: boolean
  /** 隐含全部权限（owner）。为 true 时 `permissions` 已被后端展开成全部，直接用即可 */
  grants_all?: boolean
  /** 挂着这个角色的用户数（后端 `withCount('users')` 真算；原来是前端假数据） */
  users_count?: number
  /** 默认权限基线 */
  permissions: PermissionKey[]
}

// ── 权限点 ────────────────────────────────────────────────────────────

/**
 * 内置权限点的 key。
 *
 * 之所以还要写一遍这个联合类型（而下方的 PermissionKey 是宽泛的 string）：
 * 种子数据里**代码写死**的地方靠它做编译期拼写检查；
 * 而真正跑起来之后，权限点可以在界面上新增，那时只能是 string。
 *
 * 命名统一为「模块.动作」—— 后端做鉴权中间件时可以直接拿这串当能力名。
 */
export type BuiltinPermissionKey =
  | 'user.read'
  | 'user.detail'
  | 'user.edit'
  | 'user.status'
  | 'user.mute'
  | 'user.ban'
  | 'user.role'
  | 'user.permission'
  | 'user.tag'
  | 'user.note'
  | 'user.import'
  | 'user.export'
  | 'user.delete'
  | 'blog.read'
  | 'blog.create'
  | 'blog.review'
  | 'blog.edit'
  | 'blog.publish'
  | 'blog.pin'
  | 'blog.feature'
  | 'blog.category'
  | 'blog.comment'
  | 'blog.delete'
  | 'article.read'
  | 'article.create'
  | 'article.edit'
  | 'article.preview'
  | 'article.publish'
  | 'article.schedule'
  | 'article.category'
  | 'article.revision'
  | 'article.seo'
  | 'article.delete'
  | 'notice.read'
  | 'notice.create'
  | 'notice.edit'
  | 'notice.publish'
  | 'notice.push'
  | 'notice.target'
  | 'notice.delete'
  | 'forum.read'
  | 'forum.board.create'
  | 'forum.board.edit'
  | 'forum.board.delete'
  | 'forum.post.create'
  | 'forum.post.edit'
  | 'forum.post.pin'
  | 'forum.post.feature'
  | 'forum.post.highlight'
  | 'forum.post.move'
  | 'forum.post.lock'
  | 'forum.post.delete'
  | 'forum.reply.delete'
  | 'forum.report'
  | 'forum.user.mute'
  | 'forum.statistics'
  | 'media.read'
  | 'media.create'
  | 'media.material'
  | 'media.schedule'
  | 'media.publish'
  | 'media.channel'
  | 'media.comment'
  | 'media.statistics'
  | 'media.delete'
  | 'product.read'
  | 'product.create'
  | 'product.edit'
  | 'product.version'
  | 'product.pricing'
  | 'product.delete'
  | 'license.read'
  | 'license.create'
  | 'license.batch'
  | 'license.extend'
  | 'license.transfer'
  | 'license.revoke'
  | 'license.blacklist'
  | 'license.log'
  | 'device.read'
  | 'device.unbind'
  | 'device.command'
  | 'device.alarm'
  | 'device.export'
  | 'audit.read'
  | 'audit.alert'
  | 'audit.report'
  | 'audit.export'
  | 'audit.clean'
  | 'system.admin'
  | 'system.role'
  | 'system.param'
  | 'system.notify'
  | 'system.api'
  | 'system.webhook'
  | 'system.cache'
  | 'system.backup'
  | 'system.danger'

/**
 * 运行时使用的权限标识（string，理由同 UserRole）。
 */
export type PermissionKey = string

export type PermissionItem = {
  /**
   * 后端主键（`permissions.id`）。
   *
   * 改 / 删都**用它当路由参数**，不要用 `key` —— key 允许改名，一改 URL 就变了。
   * 也正因为它是不变的 ID，后端改 key 时角色基线的引用天然不会失效。
   */
  id: number
  key: PermissionKey
  label: string
  desc: string
  /** 界面新增的权限点（相对内置的），用于在列表里打"自定义"标记 */
  custom?: boolean
}

export type PermissionGroup = {
  title: string
  items: PermissionItem[]
}

// ── 用户 ──────────────────────────────────────────────────────────────

export type UserStatus = 'active' | 'muted' | 'banned'

export type UserRecord = {
  /** 数据库自增 id。仅内部逻辑使用，**不要直接显示给用户** */
  id: number
  /**
   * 对外展示的公开 ID（形如 NMX-U-000017）。
   * 注册时由后端分配并落库，前端只负责展示，不要自己算。
   */
  publicId: string
  nickname: string
  email: string
  /** 所属角色（内置或自定义）。显示名与配色去 mock/roles.ts 里查 */
  role: UserRole
  status: UserStatus
  /** 这个人在角色基线之外**额外**调整过的权限点（含被去掉的，见下方说明） */
  permissions: PermissionKey[]
  /** 头像地址；为空时由首字母 + ID 派生色块兜底 */
  avatar?: string
  registeredAt: string
  lastSeenAt: string
}

export const STATUS_META: Record<UserStatus, { label: string; tone: ToneName; desc: string }> = {
  active: { label: '正常', tone: 'green', desc: '可正常登录与发言' },
  muted: { label: '禁言', tone: 'gold', desc: '能登录，但不能发帖评论' },
  banned: { label: '封禁', tone: 'red', desc: '禁止登录' },
}

// ── 用户详情（右侧抽屉里那份很长的业务信息） ──────────────────────────

export type UserActivityKind = 'login' | 'post' | 'license' | 'admin' | 'risk'

export type UserActivity = {
  id: number
  kind: UserActivityKind
  title: string
  detail?: string
  at: string
  ip: string
}

export type UserLicenseStatus = 'active' | 'expiring' | 'expired' | 'revoked'

export type UserLicense = {
  code: string
  product: string
  edition: string
  status: UserLicenseStatus
  machineId: string
  boundAt: string
  expireAt: string
}

export const LICENSE_STATUS_META: Record<UserLicenseStatus, { label: string; tone: ToneName }> = {
  active: { label: '生效中', tone: 'green' },
  expiring: { label: '即将到期', tone: 'gold' },
  expired: { label: '已过期', tone: 'slate' },
  revoked: { label: '已吊销', tone: 'red' },
}

export const ACTIVITY_META: Record<UserActivityKind, { label: string; tone: ToneName }> = {
  login: { label: '登录', tone: 'cyan' },
  post: { label: '发帖', tone: 'green' },
  license: { label: '授权', tone: 'violet' },
  admin: { label: '管理操作', tone: 'gold' },
  risk: { label: '风控', tone: 'red' },
}

/** 用户详情 = 基础资料 + 业务信息。右侧抽屉用这一个对象渲染 */
export type UserDetail = UserRecord & {
  phone?: string
  bio?: string
  licenses: UserLicense[]
  activity: UserActivity[]
}
