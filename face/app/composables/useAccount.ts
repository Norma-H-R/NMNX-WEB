import { computed, reactive, ref } from 'vue'
import avatarUrl from '~/assets/avatar.svg'
import { ApiError, apiRequest } from '~/composables/useApi'

/**
 * 账户状态 —— 全站共享一份（模块级单例，同 usePageVeil 的思路）。
 *
 * 为什么必须提到模块级：
 *   页头右上角那个入口要"未登录显示文字、已登录显示头像"，
 *   而登录动作发生在用户中心页里 —— 两个组件隔着路由，状态得放在它们之外。
 *   用模块级 ref 最直接，也省掉了 store 的初始化时序问题。
 *
 * 现在**已经接上后端**（core 的 /api/v1/member/*）：
 *   · login()  → POST /api/v1/member/login，拿 token + member
 *   · logout() → POST /api/v1/member/logout（吊销服务端令牌）+ 清本地
 *   · restoreSession() → 从本地读会话，再用 GET /api/v1/member/me 把资料补全
 *
 * 本地会话（会话持久化）：
 *   存在 localStorage 的 `nmnx.member.session`，形如 `{ token, expiresAt, member }`，
 *   **默认 4 小时**（SESSION_TTL）。到点由 startClock() 里的每秒心跳自动登出，
 *   不等接口报错 —— 这样"挂着页面发呆"也能在到点时自己退出。
 *
 * ⚠️ 刷新时"闪一下登录页"的坑（已修，别改回去）：
 *   `logged` 在服务端渲染时必然是 false，所以首屏不能直接按它画。
 *   恢复会话被拆成**同步段 + 异步段**（见 restoreSession）：同步段立刻定下登录态，
 *   界面靠 `sessionReady` 决定"画占位还是画真内容"。
 *   如果哪天把同步段改回 await，这个 bug 会立刻回来。
 *
 * ⚠️ 仍是假数据的部分：
 *   `licenses`（授权 / 设备 / 战报 / 订单）后端 **License / Client 模块还没做**，
 *   所以这一块继续吃占位数据，等那两个模块落地再换数据源。
 *
 * 头像走 assets 的模块引用而不是 public 绝对路径：public 里的文件会原样
 * 拷贝并用 /avatar.svg 引用，站点一旦部署到子路径下就会 404；交给打包器
 * 处理成带 hash 的资源引用才安全（同联系区那张二维码）。
 */

/** 后端 /member/* 出参里的会员字段（与 core 的 MemberResource 一一对应） */
interface MemberPayload {
  id: number
  name: string
  email: string | null
  phone: string | null
  status: string
  tier: string
  points: number
  email_verified_at: string | null
  last_login_at: string | null
  created_at: string | null
}

export interface AccountUser {
  id: number | null
  name: string
  email: string
  phone: string
  initials: string
  /** 展示用的中文等级（由 tierCode 映射而来） */
  tier: string
  /** 后端返回的等级代码（normal / silver / gold…） */
  tierCode: string
  /** 加入时间 YYYY-MM-DD（来自后端 created_at） */
  joined: string
  points: number
}

/** 等级代码 → 中文。**接口只给代码**，文案归前端管（改文案不用动后端）。 */
const TIER_LABELS: Record<string, string> = {
  normal: '普通会员',
  silver: '白银会员',
  gold: '黄金会员',
}

function tierLabel(code: string): string {
  return TIER_LABELS[code] ?? code
}

// ---------------------------------------------------------------------------
// 本地会话
// ---------------------------------------------------------------------------
const SESSION_KEY = 'nmnx.member.session'

/** 会话有效期：4 小时（用户定的默认值） */
const SESSION_TTL = 4 * 60 * 60 * 1000

interface Session {
  token: string
  expiresAt: number
  /**
   * 上次拿到的会员资料。
   *
   * 存它是为了**刷新后第一帧就能画出正确内容** —— 只用本地这份"先画"，
   * 随后总会被 `/member/me` 覆盖。不存的话，刷新时会先画一帧空名字、0 积分的面板。
   */
  member?: MemberPayload
}

/** SSR 期间没有 localStorage，所有读写都要过这一层 */
function storage(): Storage | null {
  return typeof window === 'undefined' ? null : window.localStorage
}

/** 读本地会话；不存在、格式不对、**或已过期**都返回 null */
function readSession(): Session | null {
  const store = storage()
  if (!store) return null

  try {
    const raw = store.getItem(SESSION_KEY)
    if (!raw) return null

    const parsed = JSON.parse(raw) as Session
    if (!parsed?.token || typeof parsed.expiresAt !== 'number') return null

    if (parsed.expiresAt <= Date.now()) {
      store.removeItem(SESSION_KEY)

      return null
    }

    return parsed
  } catch {
    // 存的内容坏了就当没有，不要让首页因为一行脏数据白屏
    return null
  }
}

function writeSession(session: Session): void {
  storage()?.setItem(SESSION_KEY, JSON.stringify(session))
}

// ---------------------------------------------------------------------------
// 状态
// ---------------------------------------------------------------------------
/** 登录态：未登录 / 已登录 */
const logged = ref(false)

/** 当前令牌（不对外暴露，只在本文件的请求里用） */
const token = ref<string | null>(null)

/** 本地会话的到期时间戳（0 = 没有会话） */
let expiresAt = 0

/**
 * 会话状态**是否已经确定过**。
 *
 * 为什么需要它：`logged` 在服务端渲染时必然是 `false`（服务端读不到 localStorage），
 * 所以首屏如果直接按 `logged` 画，一个**已登录的人刷新页面时会先看到登录卡**，
 * 等 `/member/me` 回来才跳成面板 —— 就是"刷新闪一下登录页"。
 *
 * 有了这个标志，界面在"还没确定"时画**占位**（既不是登录卡、也不是面板），
 * 确定之后再画正确的那一个。用法见 account.vue 与 SiteHeader.vue。
 */
const sessionReady = ref(false)

const user = reactive<AccountUser>({
  id: null,
  name: '',
  email: '',
  phone: '',
  initials: '',
  tier: tierLabel('normal'),
  tierCode: 'normal',
  joined: '',
  points: 0,
})

/** 把后端给的会员字段灌进本地 user（含代码 → 文案、ISO → 日期的映射） */
function applyMember(member: MemberPayload): void {
  user.id = member.id
  user.name = member.name ?? ''
  user.email = member.email ?? ''
  user.phone = member.phone ?? ''
  user.initials = (member.name ?? '').trim().charAt(0) || '南'
  user.tierCode = member.tier ?? 'normal'
  user.tier = tierLabel(user.tierCode)
  // ISO8601 → YYYY-MM-DD（前端要的"加入时间"，后端用 created_at 承担，不另设字段）
  user.joined = (member.created_at ?? '').slice(0, 10)
  user.points = member.points ?? 0
}

/** 清空本地用户资料（登出、或会话失效时用） */
function resetUser(): void {
  user.id = null
  user.name = ''
  user.email = ''
  user.phone = ''
  user.initials = ''
  user.tierCode = 'normal'
  user.tier = tierLabel('normal')
  user.joined = ''
  user.points = 0
}

/** 只清本地会话，**不**发请求（心跳到点、或请求已失败时用） */
function dropSession(): void {
  storage()?.removeItem(SESSION_KEY)
  token.value = null
  expiresAt = 0
  logged.value = false
  resetUser()
}

// ---------------------------------------------------------------------------
// 登录 / 登出 / 恢复会话
// ---------------------------------------------------------------------------

/**
 * 登录。
 *
 * 用法（手机号方式，测试期 phone 统一填 11111）：
 *   await login({ phone: '11111', password: '11111' })
 *
 * 失败会抛 `ApiError`（`code` 可判断、`message` 可直接显示），由调用方 catch。
 */
export async function login(payload: { phone?: string, email?: string, password: string }): Promise<void> {
  const data = await apiRequest<{ token: string, member: MemberPayload }>('/api/v1/member/login', {
    method: 'POST',
    body: { ...payload, device_name: 'web' },
  })

  // 先落会话再亮登录态：这样刷新页面时 restoreSession 能立刻接上
  token.value = data.token
  expiresAt = Date.now() + SESSION_TTL
  writeSession({ token: data.token, expiresAt, member: data.member })

  applyMember(data.member)
  logged.value = true
  sessionReady.value = true
}

/**
 * 登出：先清本地，再尽力吊销服务端令牌。
 *
 * 顺序很重要 —— 后端没起、或网络断了的时候，用户点"退出"也必须立刻退出。
 */
export async function logout(): Promise<void> {
  const previous = token.value

  dropSession()

  if (!previous) return

  try {
    await apiRequest('/api/v1/member/logout', { method: 'POST', token: previous })
  } catch {
    // 忽略：本地已经清了；服务端那条令牌会在它自己的有效期后失效
  }
}

/** 同一时刻只允许一次恢复（页头与页面都可能调它） */
let restoring: Promise<void> | null = null

/**
 * 从本地会话恢复登录态（**只在客户端调**，放 onMounted 里）。
 *
 * 分两段，顺序很重要：
 *
 *   ① **同步段**：本地会话是同步可读的，所以"是否已登录"能立刻定下来 ——
 *      用本地缓存的资料先把界面画对，并立刻把 `sessionReady` 置 true。
 *      这是"刷新不再闪登录页"的关键：**同步段里没有任何 await**。
 *
 *   ② **异步段**：再拿 token 调 `/member/me` 核对并刷新资料。
 *      失败时按状态码区分（这一点和之前不同）：
 *        · 401  → 令牌真的失效了，清掉本地会话
 *        · 其它 → **保留**本地登录态。后端挂了不该把人踢下线，
 *                 反正本地会话 4 小时到点自然会失效
 *
 * 为什么把资料也缓存在本地（虽然它会被 /me 覆盖）：
 *   只为了"第一帧有东西可画"。不缓存的话，刷新后会先画一帧
 *   空名字 / 0 积分的面板，同样难看。
 */
export function restoreSession(): Promise<void> {
  if (typeof window === 'undefined') return Promise.resolve()
  if (restoring) return restoring

  // ---------- ① 同步段：先把状态定下来 ----------
  const session = readSession()

  if (!session) {
    dropSession()
    sessionReady.value = true

    return Promise.resolve()
  }

  token.value = session.token
  expiresAt = session.expiresAt

  if (session.member) applyMember(session.member)

  logged.value = true
  sessionReady.value = true

  // ---------- ② 异步段：向后端核对 + 刷新资料 ----------
  restoring = (async () => {
    try {
      const data = await apiRequest<{ member: MemberPayload }>('/api/v1/member/me', { token: session.token })

      applyMember(data.member)
      // 顺手把缓存里的资料更新掉，下次刷新第一帧就是新的
      writeSession({ token: session.token, expiresAt: session.expiresAt, member: data.member })
    } catch (error) {
      if (error instanceof ApiError && error.httpStatus === 401) {
        dropSession()
      }
    } finally {
      restoring = null
    }
  })()

  return restoring
}

// ---------------------------------------------------------------------------
// 授权（占位数据）
//
// 一份授权 = 一个产品（EA）的一次发放。现在把"设备"和"EA 表现"也挂到授权下面：
//   · device / mode      —— 这份授权跑在哪台机器、是盈利 EA 还是测试 EA；
//   · performance        —— 今日战报（盈利/亏损的笔数与单数、今日盈亏金额）；
//   · curve / trades     —— 详情弹窗用的：当日累计盈亏曲线 + 最近几笔订单明细。
//
// ⚠️ 全部是占位数据：后端 License / Client 模块还没做（见 04-client.md）。
// ---------------------------------------------------------------------------
const licenses = reactive([
  {
    id: 'master',
    product: '南门拈星 · 主控 EA',
    edition: '终身授权 · 单机版',
    code: 'NMNX-MST-8F2C-4A91-D73E',
    from: '2026-03-12',
    to: '2027-03-12',
    expireAt: new Date('2027-03-12T00:00:00+08:00').getTime(),
    totalDays: 365,
    seats: 3,
    used: 2,

    mode: 'live', // live = 盈利 EA，demo = 测试 EA
    device: { os: 'Windows 11 · 主控端', id: 'A4F2-91C8-D0E7', active: true },
    performance: {
      runtime: '6 小时 12 分',
      pnl: '+$128.40',
      winTrades: 14,
      lossTrades: 6,
      winOrders: 3,
      lossOrders: 1,
    },
    curve: [
      { t: '09:00', v: 0 },
      { t: '10:00', v: 42.5 },
      { t: '11:00', v: -18.2 },
      { t: '12:00', v: 31.0 },
      { t: '13:00', v: 65.3 },
      { t: '14:00', v: 128.4 },
    ],
    trades: [
      { id: 'ORD-88931', time: '09:42:18', account: '402043', symbol: 'XAUUSD', side: 'buy', pnl: '+$12.40' },
      { id: 'ORD-88937', time: '10:18:55', account: '402043', symbol: 'EURUSD', side: 'sell', pnl: '-$6.20' },
      { id: 'ORD-88942', time: '10:52:03', account: '6113514', symbol: 'XAUUSD', side: 'buy', pnl: '+$48.10' },
      { id: 'ORD-88955', time: '11:37:29', account: '6113514', symbol: 'GBPUSD', side: 'sell', pnl: '+$22.75' },
      { id: 'ORD-88960', time: '12:24:11', account: '402043', symbol: 'EURUSD', side: 'buy', pnl: '-$13.90' },
      { id: 'ORD-88966', time: '13:08:44', account: '402043', symbol: 'XAUUSD', side: 'sell', pnl: '+$37.60' },
      { id: 'ORD-88971', time: '13:52:30', account: '6113514', symbol: 'USDJPY', side: 'buy', pnl: '+$27.65' },
    ],
  },
  {
    id: 'follower',
    product: '南门拈星 · 跟随端',
    edition: '年度授权 · 5 机版',
    code: 'NMNX-FLW-2B77-9C04-1AE5',
    from: '2026-04-02',
    to: '2027-04-02',
    expireAt: new Date('2027-04-02T00:00:00+08:00').getTime(),
    totalDays: 365,
    seats: 5,
    used: 3,

    mode: 'demo',
    device: { os: 'Windows Server · 跟随端', id: '7B31-2E5D-88A0', active: false },
  },
  {
    id: 'backtest',
    product: '南门拈星 · 回测引擎',
    edition: '试用授权 · 14 天',
    code: 'NMNX-BTK-TRIAL-0001',
    from: '2026-09-01',
    to: '2026-09-15',
    expireAt: new Date('2026-09-15T00:00:00+08:00').getTime(),
    totalDays: 14,
    seats: 1,
    used: 0,
  },
])

// ---------------------------------------------------------------------------
// 时钟 & 倒计时
//
// 用户可能同时握着好几份授权，所以倒计时盯的不是"某一份"，而是**最快到期的那份
// 尚未过期的授权**（primary）—— 页头那张悬浮卡和概览条读的是同一个值，两处同拍。
// 全过期了就退化成最后到期的那份，倒计时归零。
//
// ⚠️ 秒级数字在 SSR 与客户端之间必然对不齐（服务端算出的那一刻就"旧"了），
//    直接渲染会触发 hydration mismatch，所以这些数字都用 <ClientOnly> 包。
// ---------------------------------------------------------------------------
const now = ref(Date.now())
let clock = 0

/**
 * 幂等：谁先挂载谁把它点起来，之后一直跑（1s 一次，开销可忽略）。
 *
 * 顺带承担**会话超时看门狗**：本地会话到点就地登出（不发请求），
 * 这样"页面挂着发呆"也不会一直停在已登录态。
 */
function startClock(): void {
  if (typeof window === 'undefined' || clock) return

  now.value = Date.now()
  clock = window.setInterval(() => {
    now.value = Date.now()

    if (logged.value && expiresAt > 0 && expiresAt <= now.value) {
      dropSession()
    }
  }, 1000)
}

/** 主授权：未过期的里面最快到期的那份；全过期了就取最后到期的那份 */
const primary = computed(() => {
  const alive = licenses.filter((l) => l.expireAt > now.value)

  return alive.length
    ? alive.reduce((a, b) => (a.expireAt <= b.expireAt ? a : b))
    : licenses.reduce((a, b) => (a.expireAt >= b.expireAt ? a : b))
})

const countdown = computed(() => {
  const left = Math.max(0, primary.value.expireAt - now.value)
  const total = Math.floor(left / 1000)

  return {
    days: Math.floor(total / 86400),
    hours: Math.floor((total % 86400) / 3600),
    minutes: Math.floor((total % 3600) / 60),
    seconds: total % 60,
  }
})

export function useAccount() {
  return {
    logged,
    sessionReady,
    user,
    licenses,
    primary,
    now,
    countdown,
    avatarUrl,
    startClock,
    restoreSession,
    login,
    logout,
  }
}
