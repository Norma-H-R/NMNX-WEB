/**
 * 论坛数据层（**当前是假数据**）。
 *
 * 论坛和博客的分工（见 core/docs/modules/11-forum.md 与 10-blog.md 的边界）：
 *   · **博客**是长文，低频写入 → 详情可静态预渲染、按 tag 清列表；
 *   · **论坛**是高频互动，帖子短、回复多 → **详情页不做静态**，按帖 / 按版块失效。
 *   两边**共用同一套评论系统**（`useComments`），区别只在 `targetType='forum_post'`。
 *
 * ⚠️ 假数据一律确定性生成（`seeded`），不能用 `Math.random()` —— 理由同别处：
 *    服务端与浏览器各算一遍，结果不同就撞 hydration。
 *
 * 接口（待后端）：
 *   GET  /api/v1/public/forum/boards
 *   GET  /api/v1/public/forum/posts?board=…
 *   GET  /api/v1/public/forum/posts/{slug}
 *   POST /api/v1/member/forum/posts
 */

import { countComments } from '~/composables/useComments'

function seeded(seed: number) {
  let s = seed >>> 0

  return () => {
    s = (s + 0x6d2b79f5) >>> 0
    let t = Math.imul(s ^ (s >>> 15), 1 | s)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

type Rnd = () => number

const pick = <T,>(rnd: Rnd, list: readonly T[]): T => list[Math.floor(rnd() * list.length)]!
const int = (rnd: Rnd, min: number, max: number) => min + Math.floor(rnd() * (max - min + 1))

export interface Member {
  id: number
  name: string
}

export interface Board {
  id: number
  slug: string
  name: string
  desc: string
  hue: number
}

export interface ForumPost {
  id: number
  slug: string
  boardId: number
  title: string
  excerpt: string
  /** Markdown 正文 */
  body: string
  author: Member
  tags: string[]
  /** 置顶：1 = 置顶，0 = 普通 */
  pin: number
  views: number
  likes: number
  commentCount: number
  createdAt: string
  lastReplyAt: string
}

// ---------------------------------------------------------------------------
// 版块
// ---------------------------------------------------------------------------
export const BOARDS: Board[] = [
  {
    id: 1,
    slug: 'announce',
    name: '公告与更新',
    desc: '版本发布、维护窗口、规则变更。只读为主。',
    hue: 192,
  },
  {
    id: 2,
    slug: 'help',
    name: '使用求助',
    desc: '装不上、连不上、对不上账 —— 先在这里问。',
    hue: 42,
  },
  {
    id: 3,
    slug: 'share',
    name: '经验分享',
    desc: '参数、脚本、踩坑记录，欢迎贴实测数据。',
    hue: 262,
  },
  {
    id: 4,
    slug: 'strategy',
    name: '策略讨论',
    desc: '信号逻辑、仓位分配、风控阈值。',
    hue: 12,
  },
  {
    id: 5,
    slug: 'offtopic',
    name: '灌水区',
    desc: '和交易没关系的话题放这儿。',
    hue: 145,
  },
]

const boardOf = (id: number) => BOARDS.find((b) => b.id === id)!

// ---------------------------------------------------------------------------
// 作者与标题素材
// ---------------------------------------------------------------------------
const AUTHORS: Member[] = [
  { id: 2, name: '南门会员' },
  { id: 7, name: '星河' },
  { id: 11, name: '灰度观察员' },
  { id: 15, name: '夜航' },
  { id: 18, name: '老K' },
  { id: 23, name: '点阵工' },
  { id: 31, name: 'Dora' },
  { id: 44, name: '半仓先生' },
  { id: 52, name: '不追高' },
  { id: 63, name: 'Aki' },
  { id: 77, name: '旧时钟' },
  { id: 88, name: '慢就是快' },
]

const TITLES: Record<number, string[]> = {
  1: [
    '跟随端 v7.1.2 已发布，覆盖安装即可',
    '10 月 5 日 02:00-04:00 授权服务维护',
    '关于近期两个冒名下注群的说明',
    '回测引擎试用授权开放申请（14 天）',
    '主控 EA v7.0 灰度完成，全量放开',
  ],
  2: [
    '离线激活码换机器要走什么流程',
    '跟随端连不上主控，日志里全是 timeout',
    '同一套信号两个账户结果差很多，正常吗',
    '授权宽限期到底怎么算的',
    '装了 7.1 之后回测报告读不出来了',
    '点阵看板在小屏上错位',
    '换服务器之后机器指纹变了，要重新激活吗',
    '日志探针采不到断线那一段',
    '积分商城的试用授权在哪兑换',
    '多机同步偶尔会差一帧，有人遇到过吗',
  ],
  3: [
    '点阵延迟 3ms 是怎么测出来的',
    '我把风控阈值调成这样，回撤小了一半',
    '断线重连的三个坑，附配置',
    '回测别只看收益率，看这三个指标',
    '关于滑点补偿的一次实测',
    '日志采样率我踩过的坑',
    '一个反直觉的结论：少改参数比多改有用',
    '从手动到自动的这两年',
    '授权宽限期救了我一次',
    '把主控钉在单核上之后稳多了',
    '记录一次失败的上线',
    '给新人的一页纸',
  ],
  4: [
    '开仓确认压到一帧内，代价是什么',
    '仓位分配：等额还是按波动率',
    '连续亏损熔断，阈值设多少合适',
    '信号去重到底该在哪一层做',
    '关于"不信本地时钟"的讨论',
    '主控和跟随端的信号口径要不要统一',
    '多机场景下的风控归属问题',
    '极端行情里的下单优先级',
    '策略失效的早期信号有哪些',
    '回测和实盘差距的三个来源',
  ],
  5: [
    '你们盯盘的时候在干什么',
    '显示器怎么摆比较舒服',
    '熬夜盯盘这事儿真的有意义吗',
    '推荐一个安静的键盘',
    '今天你亏了吗（每日签到）',
    '聊聊你们的第一台交易机',
    '有没有人和我一样会把日志打印出来看',
  ],
}

const TAGS_POOL = ['求助', '已解决', '实测', '复盘', '讨论', '配置', '坑', '分享', '提问', '置顶']

const BODY_BLOCKS = [
  '## 现象\n\n上周三开始偶发，一天大概两三次，重启之后能好一阵。\n\n## 我试过的\n\n1. 换服务器 —— 没用\n2. 把日志级别调到 debug —— 能看到 timeout，但看不出为什么\n3. 换网络 —— 稍微好一点，但没根治',
  '## 背景\n\n我们两台机器跑同一套信号，一台主控一台跟随。\n\n## 结论\n\n问题不在信号，在**时钟**上。跟随端的本地时间比主控慢了 40ms 左右。',
  '## 配置贴在这里\n\n```ini\nrisk.daily_drawdown = 0.06\nrisk.max_consecutive_loss = 4\nrisk.per_order_cap = 0.02\n```\n\n这套跑了三周，回撤从 11% 降到 6% 左右。',
  '> 先把结论放前面：**能在边界上固定下来的问题，都不算难题。**\n\n下面是推导过程，赶时间可以跳过。',
  '## 数据\n\n| 场景 | 改前 | 改后 |\n|---|---|---|\n| 常规行情 | 18ms | 17ms |\n| 极端行情 | 41ms | 19ms |\n| 断线重连 | 220ms | 195ms |',
]

// ---------------------------------------------------------------------------
// 帖子
// ---------------------------------------------------------------------------
function buildPosts(): ForumPost[] {
  const rnd = seeded(20261005)
  const out: ForumPost[] = []
  let id = 100

  BOARDS.forEach((board) => {
    const titles = TITLES[board.id] ?? []

    titles.forEach((title, idx) => {
      id += 1
      const day = int(rnd, 0, 40)
      const created = Date.UTC(2026, 9, 4) - day * 86_400_000
      const replyDay = day - int(rnd, 0, Math.min(day, 5))
      const iso = (t: number) => new Date(t).toISOString().replace('.000Z', '+00:00')

      out.push({
        id,
        slug: `t-${board.slug}-${idx + 1}`,
        boardId: board.id,
        title,
        excerpt: pick(rnd, [
          '先说结论：不是配置问题，是时钟。',
          '折腾了两天，把过程记一下，希望别人少走弯路。',
          '有没有人遇到过一样的情况？',
          '附上我的配置，欢迎拍砖。',
          '这个坑我踩了两次，第二次才想明白。',
        ]),
        body: [pick(rnd, BODY_BLOCKS), pick(rnd, BODY_BLOCKS)].join('\n\n'),
        author: pick(rnd, AUTHORS),
        tags: [pick(rnd, TAGS_POOL)].filter(Boolean),
        // 每个版块的第一条置顶
        pin: idx === 0 ? 1 : 0,
        views: int(rnd, 40, 8600),
        likes: int(rnd, 0, 260),
        commentCount: 0, // 稍后从评论层回填
        createdAt: iso(created),
        lastReplyAt: iso(Math.max(created, Date.UTC(2026, 9, 4) - replyDay * 86_400_000)),
      })
    })
  })

  return out
}

const POSTS: ForumPost[] = buildPosts()

// 评论数从评论层拿：详情页的评论树走 useComments，两处必须同源
POSTS.forEach((p) => {
  p.commentCount = countComments('forum_post', p.id)
})

// ---------------------------------------------------------------------------
// 对外
// ---------------------------------------------------------------------------

/** ← GET /api/v1/public/forum/boards（带每版的帖子数） */
export function fetchBoards() {
  return BOARDS.map((b) => {
    const posts = POSTS.filter((p) => p.boardId === b.id)

    return {
      ...b,
      postCount: posts.length,
      replyCount: posts.reduce((s, p) => s + p.commentCount, 0),
      lastReplyAt: posts.reduce((m, p) => (p.lastReplyAt > m ? p.lastReplyAt : m), ''),
    }
  })
}

/** ← GET /api/v1/public/forum/posts（置顶在前，其余按最后回复倒序） */
export function fetchPosts(boardSlug?: string): ForumPost[] {
  const board = boardSlug ? BOARDS.find((b) => b.slug === boardSlug) : null
  const list = board ? POSTS.filter((p) => p.boardId === board.id) : POSTS

  return [...list].sort((a, b) => {
    if (a.pin !== b.pin) return b.pin - a.pin

    return a.lastReplyAt < b.lastReplyAt ? 1 : -1
  })
}

/** ← GET /api/v1/public/forum/posts/{slug} */
export function fetchPost(slug: string): ForumPost | null {
  return POSTS.find((p) => p.slug === slug) ?? null
}

export const boardName = (id: number) => boardOf(id)?.name ?? ''
export const boardSlug = (id: number) => boardOf(id)?.slug ?? ''

export function fetchBoard(slug: string): Board | null {
  return BOARDS.find((b) => b.slug === slug) ?? null
}

/** 帖子总数 / 回复总数 —— 论坛首页顶部那一行统计 */
export function forumStats() {
  return {
    posts: POSTS.length,
    replies: POSTS.reduce((s, p) => s + p.commentCount, 0),
    boards: BOARDS.length,
    today: POSTS.filter((p) => p.createdAt.slice(0, 10) === '2026-10-04').length,
  }
}
