import { computed, ref } from 'vue'

/**
 * 通知 / 公告。
 *
 * 分两类，**展示位置是刻意分开的**（需求里明确要求）：
 *
 *   announcement  系统公告 —— 见下面"两条通道"；
 *   reply         博客 / 论坛回复 —— 不弹窗、页头也不提示，**必须登录并进到
 *                                   用户中心的「通知」里**才看得到。
 *
 * 公告又按"要不要打断操作"分成两条通道：
 *
 *   warn（维护、风控这类）→ **默认弹窗**（NoticeModal），要求用户确认一下；
 *   其余普通公告          → **右侧流光通知条**（NoticeStack），不打断、几秒自己退。
 *
 * ⚠️ 为什么必须分开（踩过的坑）：早先两条通道都吃"未读公告"、还共用一套已读 ——
 *    弹窗先弹出来，用户一关就等于标记已读，通知条那边一看"这条读过了"就把自己取消，
 *    表现就是"通知条从来没出现过"。现在一条公告只走一条通道，各自不打架。
 *
 * **能不能点**：带 `body`（详情正文）的通知可点击 —— 通知条上会带一个「›」标识，
 * 点一下直接进 /notices/{id} 详情页；没带 body 的（纯通报，没有更多可说的）不可点。
 * 判断统一走 noticeLink()，别在模板里各写各的。
 *
 * 已读状态落在 localStorage。key 带 v2。未读数（页头头像那个红点）是两条通道
 * 合起来算的：只要还有公告没看过就提示。
 *
 * ⚠️ 数据是写死的占位，落地时换成接口：公告走 /api/v1/public，回复走用户维度的
 *    通知接口；已读也建议挪到服务端记，换设备才能同步。
 */

export interface Notice {
  id: string
  kind: 'announcement' | 'reply'
  title: string
  /** 列表里显示的一行摘要（也是通知条上的次要文字） */
  text: string
  /** 详情页的正文段落 —— 有它才可点击 */
  body?: string[]
  time: string
  /** 公告级别：普通（走通知条）/ warn（走弹窗） */
  level?: 'info' | 'warn'
}

const READ_KEY = 'nmnx:notice.v2'

const announcements = ref<Notice[]>([
  {
    id: 'an-20261003',
    kind: 'announcement',
    title: '跟随端 v7.1 已发布',
    text: '开仓确认压到一帧内，并修掉了极端行情下偶发的重复下单。',
    body: [
      '本次更新把跟随端的开仓确认压到一帧内 —— 此前在极端行情下最坏会拖到 40ms 以上，跟不上的仓位会直接错过。',
      '同时修掉了一个偶发问题：主控与跟随端在同一时刻收到两个反向信号时，跟随端有概率重复下单。',
      '覆盖安装即可，授权与机器绑定关系不受影响。已授权用户可以直接在用户中心里重新下载，不用另外申请。',
    ],
    time: '2026-10-03 10:20',
    level: 'info',
  },
  {
    id: 'an-20260930',
    kind: 'announcement',
    title: '10 月 5 日凌晨授权服务维护',
    text: '02:00 - 04:00 授权校验服务做一次升级维护，不影响已开仓位。',
    body: [
      '维护窗口：10 月 5 日 02:00 - 04:00（约 2 小时）。',
      '期间授权校验服务不可用，EA 会走本地缓存与宽限期继续运行，已开仓位不受影响，也不需要手动干预。',
      '需要注意的只有一点：这段时间内不要解绑或换机 —— 那类操作要连服务端，会失败。',
    ],
    time: '2026-09-30 18:00',
    level: 'warn',
  },
  {
    id: 'an-20260926',
    kind: 'announcement',
    title: '回测引擎试用授权开放申请',
    text: '普通会员也能申请 14 天试用，在用户中心的积分商城里兑换即可。',
    body: [
      '回测引擎正式面向普通会员开放试用，14 天，功能与正式授权一致。',
      '兑换入口在用户中心 → 积分商城，消耗 500 积分，每个账户限一次。',
      '试用期内产生的回测报告会保留在账户下，升正式授权后仍然能看。',
    ],
    time: '2026-09-26 09:40',
    level: 'info',
  },
  {
    id: 'an-20260918',
    kind: 'announcement',
    title: '主控 EA v7.0 灰度完成',
    text: '全量放开，无需手动升级。',
    body: [
      '主控 EA v7.0 的灰度观察期结束，各项指标与 v6.x 持平，信号线程占用下降约 18%。',
      '本次为服务端下发策略更新，客户端无需做任何操作。',
    ],
    time: '2026-09-18 14:05',
    level: 'info',
  },
  {
    id: 'an-20260910',
    kind: 'announcement',
    title: '关于第三方登录的说明',
    text: '第三方登录仅用于创建账户，登录后仍需绑定手机号。',
    // 故意不带 body：纯通报，没有更多可说的，因此**不可点击**
    time: '2026-09-10 11:30',
    level: 'info',
  },
])

const replies = ref<Notice[]>([
  {
    id: 'rp-20261003-a',
    kind: 'reply',
    title: '离线激活码换机器要走什么流程',
    text: '论坛 · 星河 回复了你',
    body: [
      '星河：直接在新机器上激活就行，旧机器会自动降为未激活状态，不需要先解绑。',
      '星河：不过如果旧机器还在跑实盘，建议先停掉再换，避免两边同时占着同一个席位。',
    ],
    time: '2 小时前',
  },
  {
    id: 'rp-20261002-b',
    kind: 'reply',
    title: '点阵延迟 3ms 是怎么测出来的',
    text: '博客 · 收到 1 条新评论',
    body: [
      '匿名用户：文里的 3ms 是端到端延迟还是只统计了渲染那一段？',
      '你：端到端，从数据落库到点阵上出下一帧，采样方式是每 100ms 打一个包、连续打 10 分钟取 P95。',
    ],
    time: '昨天 21:40',
  },
  {
    id: 'rp-20260930-c',
    kind: 'reply',
    title: '跟随端 2 台和 6 台的差距在哪里',
    text: '论坛 · 有人引用了你的回复',
    body: ['「…实测 2 台到 6 台的差异主要出现在同时开仓那一下，4 台以内基本看不出区别。」'],
    time: '09-30 16:12',
  },
])

// ---------------------------------------------------------------------------
// 已读
// ---------------------------------------------------------------------------
const readIds = ref<string[]>([])
let loaded = false

/** 幂等；只在客户端调用（SSR 没有 localStorage） */
function loadRead() {
  if (loaded || typeof localStorage === 'undefined') return
  loaded = true

  try {
    const raw = localStorage.getItem(READ_KEY)
    readIds.value = raw ? (JSON.parse(raw) as string[]) : []
  } catch {
    readIds.value = []
  }
}

function saveRead() {
  if (typeof localStorage === 'undefined') return

  try {
    localStorage.setItem(READ_KEY, JSON.stringify(readIds.value))
  } catch {
    // 存不进去就算这次不记，不影响浏览
  }
}

function markRead(id: string) {
  if (readIds.value.includes(id)) return
  readIds.value = [...readIds.value, id]
  saveRead()
}

/** 模板里直接调，读的是 readIds，所以已读变化会带动重渲染 */
const isRead = (n: Notice) => readIds.value.includes(n.id)

/** 可点击的通知才有详情页；判断收在这里，模板里别各写各的 */
const noticeLink = (n: Notice) => (n.body?.length ? `/notices/${n.id}` : '')

// ---------------------------------------------------------------------------
// 派生
// ---------------------------------------------------------------------------

/** 需要确认的公告：走弹窗 */
const dialogNotices = computed(() => announcements.value.filter((n) => n.level === 'warn'))

/** 普通公告：走右侧通知条 */
const toastNotices = computed(() => announcements.value.filter((n) => n.level !== 'warn'))

/** 还没看过的公告（两条通道合并算）—— 页头那个红点用的是它 */
const unreadAnnouncements = computed(() => announcements.value.filter((n) => !isRead(n)))

/** 弹窗要显示的那条：最新的未读「需确认」公告（已读一条就顺延到下一条） */
const latestUnread = computed(() => dialogNotices.value.find((n) => !isRead(n)) ?? null)

/** 通知条要推的：未读的普通公告 */
const unreadToastNotices = computed(() => toastNotices.value.filter((n) => !isRead(n)))

const unreadReplies = computed(() => replies.value.filter((n) => !isRead(n)))

/** 用户中心「通知」用的：公告 + 回复混排 */
const notices = computed(() => [...announcements.value, ...replies.value])

/** 通知列表页用的：按时间倒序（这里的时间是展示串，占位数据直接按数组顺序当作倒序） */
const noticeFeed = computed(() => [...announcements.value, ...replies.value])

/** 按 id 取一条，给详情页用 */
const findNotice = (id: string) => notices.value.find((n) => n.id === id) ?? null

const unreadTotal = computed(() => unreadAnnouncements.value.length + unreadReplies.value.length)

/** 调试面板用：本地已经记了多少条已读 */
const readCount = computed(() => readIds.value.length)

export function useNotices() {
  return {
    notices,
    noticeFeed,
    announcements,
    replies,
    dialogNotices,
    toastNotices,
    unreadAnnouncements,
    unreadToastNotices,
    unreadReplies,
    unreadTotal,
    readCount,
    latestUnread,
    isRead,
    markRead,
    loadRead,
    noticeLink,
    findNotice,
  }
}
