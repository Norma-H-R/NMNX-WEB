/**
 * 博客数据层 —— **当前整层都是假数据**，接口一落地就替换函数体。
 *
 * 规格见 `core/docs/modules/10-blog.md`（v0.4.0）。这里导出的每个函数都对应那儿的一个接口：
 *
 *   fetchBlogs()            ← GET  /api/v1/public/blogs
 *   fetchBlog(slug)         ← GET  /api/v1/public/blogs/{slug}
 *   fetchComments(target)   ← GET  /api/v1/comments?target_type=…&target_id=…
 *   reactTo(...)            ← POST /api/v1/member/blogs/{id}/reactions
 *
 * ⚠️ **假数据不许用 `Math.random()`。** 这些数据在服务端渲染时生成一遍、客户端 hydration
 *    时又生成一遍，两次结果只要差一个数，Vue 就会报 hydration mismatch，页面直接崩。
 *    所以统一走下面的 `seeded()` —— 同一颗种子永远给同一串数。
 *
 * ⚠️ 评论是**独立系统**（见文档决策 9）：这里的 `CommentThread` 只认 `targetType` /
 *    `targetId`，不认识"博客"。论坛落地时这套原样拿去用。
 */

// 评论是**独立系统**（见 10-blog.md 决策 9），这里只引用它的计数，
// 不自己造评论 —— 否则列表页显示的条数会和详情页的评论树对不上。
import { countComments } from '~/composables/useComments'

// ---------------------------------------------------------------------------
// 确定性伪随机（mulberry32）
// ---------------------------------------------------------------------------
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

// ---------------------------------------------------------------------------
// 类型
// ---------------------------------------------------------------------------
export interface Member {
  id: number
  name: string
}

export interface BlogStats {
  like: number
  favorite: number
  block: number
  view: number
  comment: number
}

export interface BlogAttachment {
  id: string
  /** 后端按 MIME 判定，前端只负责渲染 —— 别在前端按扩展名猜 */
  kind: 'image' | 'audio' | 'video' | 'doc' | 'sheet' | 'pdf' | 'other'
  url: string
  name: string
  size: number
}

export interface Blog {
  id: number
  slug: string
  title: string
  excerpt: string
  /** Markdown 源 */
  body: string
  /** null 表示作者没上传封面 —— 前端渲染占位块（决策 8） */
  cover: string | null
  author: Member
  tags: string[]
  stats: BlogStats
  /**
   * 当前登录会员对这篇的三个状态。
   * 接口出参里叫 `mine`（未登录时为 null），这里一律给个对象，省得模板到处判空。
   */
  mine: { liked: boolean, favorited: boolean, blocked: boolean }
  atts: BlogAttachment[]
  status: 'draft' | 'published'
  publishedAt: string
  updatedAt: string
}

export interface CommentStats {
  like: number
  dislike: number
  favorite: number
  reply: number
}

export interface Comment {
  id: number
  parentId: number | null
  depth: number
  /** 物化路径，形如 `0000012/0000451/`；取子树靠 `path LIKE '0000012/%'` */
  path: string
  user: Member
  body: string
  stats: CommentStats
  createdAt: string
  children: Comment[]
  /**
   * 当前登录会员对这条评论的操作状态。
   * 接口出参里叫 `mine`；这里挂成 `mineState` 是因为真接口来之前，
   * **`mine` 是本地改的**（乐观更新），需要有个地方放得住。
   */
  mineState?: { liked?: boolean, disliked?: boolean, favorited?: boolean }
}

// ---------------------------------------------------------------------------
// 素材池
// ---------------------------------------------------------------------------
const MEMBERS: Member[] = [
  { id: 2, name: '南门会员' },
  { id: 7, name: '星河' },
  { id: 11, name: '灰度观察员' },
  { id: 15, name: '夜航' },
  { id: 18, name: '老K' },
  { id: 23, name: '点阵工' },
  { id: 31, name: 'Dora' },
  { id: 44, name: '半仓先生' },
]

const TOPICS = [
  '点阵延迟', '开仓确认', '离线激活', '席位扩容', '跟单滑点',
  '极端行情', '回测曲线', '授权宽限期', '多机同步', '订单去重',
  '本地缓存', '风控闸', '仓位分配', '信号抖动', '断线重连',
  '平仓时机', '滑点补偿', '机器指纹', '灰度发布', '日志采样',
]

const SHAPES = [
  '是怎么测出来的', '的一次踩坑记录', '背后的取舍', '到底值不值',
  '为什么我要换掉它', '上线三周的复盘', '被问到最多的三个问题',
  '和去年的方案对比', '一个反直觉的结论', '写在返工之后',
]

const TAGS = [
  '实测', '架构', '性能', '踩坑', '复盘', '监控',
  '回测', '风控', '工程', '延迟', '稳定性', '心得',
]

const EXCERPTS = [
  '端到端，从数据落库到出下一帧，采样方式是每 100ms 打一个包、连续打 10 分钟取 P95。',
  '结论先说：大部分场景下它比想象中简单，坑都在边界条件上。',
  '把三次返工的过程完整记下来，希望你别再踩一遍。',
  '用两组对照数据说话，其中一组是反例。',
  '这篇写得比较长，建议先看结论，后面是推导过程。',
  '一句话版本：不是算法问题，是时钟问题。',
]

const BODY_BLOCKS = [
  '## 起因\n\n事情是这样的：上周有用户反馈，极端行情下偶发重复下单。我第一反应是信号线程的问题，查了两天才发现根子在时钟上。',
  '## 我的做法\n\n1. 先把现象固定下来，写了一个能稳定复现的脚本\n2. 打点，确认不是我以为的那一段慢\n3. 改一处、测一次，不攒着一起改',
  '## 实测数据\n\n| 场景 | 改前 | 改后 |\n|---|---|---|\n| 常规行情 | 18ms | 17ms |\n| 极端行情 | 41ms | 19ms |\n| 断线重连 | 220ms | 195ms |',
  '## 结论\n\n问题不在算法，而在于我用了一个不该信的本地时钟。换成服务端校准之后，抖动从 40ms 降到 3ms 以内。',
  '> 一句话：**能在边界上固定下来的问题，都不算难题。**',
  '## 还没解决的\n\n多机场景下偶尔还是会差一帧，目前只能靠事后补偿。这块留到下个版本。',
  '## 为什么不用现成的方案\n\n试过三个开源库，两个不支持我们要的断线补偿，第三个 API 设计得很漂亮但依赖太重。',
  '```js\n// 关键就这一行：不信本地时钟\nconst t = await sync.now()\n```',
]

/** 生成一张 SVG 封面（data URI）—— 假数据不引外部图片，省得 404 */
function coverSvg(rnd: Rnd): string {
  const h1 = int(rnd, 180, 280)
  const h2 = (h1 + int(rnd, 40, 120)) % 360
  const svg
    = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 360">`
      + `<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">`
      + `<stop offset="0" stop-color="hsl(${h1},72%,58%)"/>`
      + `<stop offset="1" stop-color="hsl(${h2},66%,44%)"/>`
      + `</linearGradient></defs>`
      + `<rect width="640" height="360" fill="#0b0f1a"/>`
      + `<rect width="640" height="360" fill="url(#g)" opacity="0.55"/>`
      + `<circle cx="${int(rnd, 80, 560)}" cy="${int(rnd, 60, 300)}" r="${int(rnd, 60, 150)}"`
      + ` fill="#ffffff" opacity="0.12"/>`
      + `<circle cx="${int(rnd, 80, 560)}" cy="${int(rnd, 60, 300)}" r="${int(rnd, 30, 90)}"`
      + ` fill="#ffffff" opacity="0.08"/>`
      + `</svg>`

  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`
}

/** 附件：刻意把六种 kind 都造出来，好把渲染分支全试到 */
function makeAttachments(rnd: Rnd, blogId: number): BlogAttachment[] {
  const atts: BlogAttachment[] = []

  if (rnd() < 0.55) {
    atts.push({
      id: `a-${blogId}-img`,
      kind: 'image',
      url: coverSvg(seeded(blogId * 31)),
      name: `对比图-${int(rnd, 1, 9)}.png`,
      size: int(rnd, 80_000, 640_000),
    })
  }

  if (rnd() < 0.5) {
    atts.push({
      id: `a-${blogId}-audio`,
      kind: 'audio',
      url: '/samples/tone.wav',
      name: `复盘录音-${int(rnd, 1, 20)}.wav`,
      size: 66_194,
    })
  }

  if (rnd() < 0.3) {
    atts.push({
      id: `a-${blogId}-video`,
      kind: 'video',
      url: '/samples/screen.mp4',
      name: `录屏-复现过程.mp4`,
      size: int(rnd, 4_000_000, 28_000_000),
    })
  }

  if (rnd() < 0.3) {
    atts.push({
      id: `a-${blogId}-doc`,
      kind: 'doc',
      url: '/samples/notes.docx',
      name: `排查笔记-v${int(rnd, 1, 6)}.docx`,
      size: int(rnd, 40_000, 900_000),
    })
  }

  if (rnd() < 0.3) {
    atts.push({
      id: `a-${blogId}-sheet`,
      kind: 'sheet',
      url: '/samples/samples.xlsx',
      name: `原始数据-${int(rnd, 10, 30)}组.xlsx`,
      size: int(rnd, 20_000, 400_000),
    })
  }

  if (rnd() < 0.25) {
    atts.push({
      id: `a-${blogId}-pdf`,
      kind: 'pdf',
      url: '/samples/report.pdf',
      name: `回测报告-${int(rnd, 1, 12)}月.pdf`,
      size: int(rnd, 200_000, 3_000_000),
    })
  }

  return atts
}

// ---------------------------------------------------------------------------
// 50 篇博客
// ---------------------------------------------------------------------------
function buildBlogs(total = 50): Blog[] {
  const rnd = seeded(20261004)
  const list: Blog[] = []
  const used = new Set<string>()

  for (let i = 1; i <= total; i++) {
    const topic = TOPICS[(i - 1) % TOPICS.length]!
    const shape = SHAPES[Math.floor((i - 1) / TOPICS.length) % SHAPES.length]!
    const title = `${topic}${shape}`

    // slug：真实后端会用拼音（overtrue/pinyin），这里直接沿用拼音形态
    let slug = `blog-${i}`
    if (!used.has(slug)) used.add(slug)
    else slug = `blog-${i}-2`

    const blocks = [pick(rnd, BODY_BLOCKS), pick(rnd, BODY_BLOCKS), pick(rnd, BODY_BLOCKS)]
    const body = `![封面](${coverSvg(seeded(i * 17))})\n\n${blocks.join('\n\n')}`

    const days = int(rnd, 0, 120)
    const date = new Date(Date.UTC(2026, 9, 4) - days * 86_400_000)
    const iso = date.toISOString().replace('.000Z', '+00:00')

    list.push({
      id: i,
      slug,
      title,
      excerpt: pick(rnd, EXCERPTS),
      body,
      // 每 4 篇留一个没封面的，专门用来验占位块
      cover: i % 4 === 0 ? null : coverSvg(seeded(i * 7919)),
      author: MEMBERS[i % MEMBERS.length]!,
      tags: [pick(rnd, TAGS), pick(rnd, TAGS)].filter((t, idx, arr) => arr.indexOf(t) === idx),
      stats: {
        like: int(rnd, 3, 320),
        favorite: int(rnd, 0, 96),
        block: int(rnd, 0, 12),
        view: int(rnd, 120, 9800),
        comment: 0, // 稍后按实际生成的评论数回填
      },
      // 随机给一部分种上"我已点过"的状态，好把点亮态的样式也试到
      mine: {
        liked: rnd() < 0.25,
        favorited: rnd() < 0.12,
        blocked: rnd() < 0.04,
      },
      atts: makeAttachments(rnd, i),
      status: i % 11 === 0 ? 'draft' : 'published',
      publishedAt: iso,
      updatedAt: iso,
    })
  }

  return list
}

// ---------------------------------------------------------------------------
// 100 条评论（树形）
// ---------------------------------------------------------------------------
const COMMENT_BODIES = [
  '这个我踩过，最后也是卡在时钟上。',
  '请问 P95 是怎么算的？直接取的采样点吗',
  '收藏了，回头照着排查一遍。',
  '第 3 步我持保留意见，我们那边实测没那么简单。',
  '写得很清楚，比官方文档好用。',
  '同意结论，但多机那块是不是还有别的原因？',
  '楼主能贴一下那个复现脚本吗',
  '看完觉得自己之前白折腾了两个月……',
  '这个方案在断线超过 30 秒的时候还成立吗？',
  '补充一点：如果机器时间被 NTP 校正过，结论可能反过来。',
  '刚好在做这块，省了我不少时间，谢谢。',
  '不认同"不是算法问题"，我们遇到的就是算法问题。',
  '有没有考虑过用单调时钟？',
  '数据表能不能补一列标准差',
  '试了，有效。就是日志量有点大。',
  '太硬核了，先马后看。',
  '这个坑我也踩过，我当时是因为容器时区。',
  '想请教一个问题：宽限期那段怎么处理的',
]

const pad6 = (n: number) => String(n).padStart(6, '0')

/**
 * 造一棵评论树。
 *
 * 规则刻意做得"像真的"：约 6 成是直接评论博客的顶层评论，其余是回复；
 * 回复**偏向挑最近的几条**，这样会自然形成一串一串的对话，而不是均匀散开。
 * 深度随机到 6 层 —— 正好用来验前端的折叠逻辑。
 */
function buildCommentsFor(blog: Blog, count: number, rnd: Rnd, idBase: { n: number }): Comment[] {
  const roots: Comment[] = []
  const flat: Comment[] = []

  for (let i = 0; i < count; i++) {
    const id = ++idBase.n

    let parent: Comment | null = null
    if (i >= 2 && rnd() < 0.6) {
      // 只在 6 层以内挑爹；再深前端就折叠了
      const candidates = flat.filter((c) => c.depth < 6)
      if (candidates.length) {
        // 池子取小一点、且只从"最近几条"里挑 —— 这样才会一串一串往下扎，
        // 而不是均匀撒开（Reddit 那种长链就是这么来的）
        const pool = candidates.slice(-8)
        parent = pool[Math.floor(rnd() * pool.length)]!
      }
    }

    const comment: Comment = {
      id,
      parentId: parent ? parent.id : null,
      depth: parent ? parent.depth + 1 : 1,
      path: '',
      user: pick(rnd, MEMBERS.filter((m) => m.id !== blog.author.id)),
      body: pick(rnd, COMMENT_BODIES),
      stats: {
        like: int(rnd, 0, 46),
        dislike: int(rnd, 0, 6),
        favorite: int(rnd, 0, 14),
        reply: 0,
      },
      createdAt: new Date(Date.UTC(2026, 9, 4) - int(rnd, 0, 20) * 3_600_000)
        .toISOString()
        .replace('.000Z', '+00:00'),
      children: [],
      // 随机给一部分种上"我已点过"，好把点亮态也试到
      mineState: {
        liked: rnd() < 0.2,
        disliked: rnd() < 0.06,
        favorited: rnd() < 0.08,
      },
    }

    comment.path = parent ? `${parent.path}${pad6(id)}/` : `${pad6(id)}/`

    if (parent) {
      parent.children.push(comment)
      parent.stats.reply += 1
    } else {
      roots.push(comment)
    }

    flat.push(comment)
  }

  return roots
}

function buildComments(blogs: Blog[], total = 100): Record<number, Comment[]> {
  const rnd = seeded(77213)
  const idBase = { n: 0 }
  const map: Record<number, Comment[]> = {}
  let made = 0

  blogs.forEach((blog, i) => {
    const left = blogs.length - i
    const remain = total - made

    // 余量按剩余篇数分配，但**前几篇刻意多分**：
    // 评论全堆在一处才看得出深层嵌套；均摊到 50 篇的话每篇只剩两条，树根本长不起来。
    // bias 6 的意思大致是"头几篇各拿十来条"，剩下的零头再摊给其余各篇。
    // 最后一篇兜底收干净，保证总数刚好 100。
    // 头几篇给**固定条数**，而不是按比例随机 ——
    // 按比例的话运气差一点就只分到六七条，树扎不下去，根本看不到深层嵌套的效果。
    const FIXED = [18, 15, 12, 10, 8, 6]
    let n = i === blogs.length - 1
      ? remain
      : i < FIXED.length
        ? Math.min(FIXED[i]!, remain)
        : Math.max(1, Math.round((remain / left) * 0.75))

    n = Math.min(n, remain)

    if (n > 0) {
      map[blog.id] = buildCommentsFor(blog, n, rnd, idBase)
      made += n
      // ⚠️ 计数改从**评论层**拿，别用这里造的 n ——
      // 详情页的评论树走的是 useComments（独立系统），两处必须同源
      blog.stats.comment = countComments('blog', blog.id)
    }
  })

  return map
}

// ---------------------------------------------------------------------------
// 组装（模块级只算一次）
// ---------------------------------------------------------------------------
const BLOGS = buildBlogs(50)
const COMMENTS = buildComments(BLOGS, 100)
const COMMENT_INDEX = new Map<number, Comment>()

function indexComments(list: Comment[]) {
  list.forEach((c) => {
    COMMENT_INDEX.set(c.id, c)
    indexComments(c.children)
  })
}

Object.values(COMMENTS).forEach(indexComments)

/** 当前登录会员（假数据里固定是 2 号；真实环境来自 useAccount） */
const ME = 2

// ---------------------------------------------------------------------------
// 对外：签名对齐 core 的接口，接口好了只换函数体
// ---------------------------------------------------------------------------

/** ← GET /api/v1/public/blogs */
export function fetchBlogs(): Blog[] {
  return [...BLOGS].sort((a, b) => (a.publishedAt < b.publishedAt ? 1 : -1))
}

/** ← GET /api/v1/public/blogs/{slug} */
export function fetchBlog(slug: string): Blog | null {
  return BLOGS.find((b) => b.slug === slug) ?? null
}

/** 我的博客（含草稿）← GET /api/v1/member/blogs */
export function fetchMyBlogs(): Blog[] {
  return fetchBlogs().filter((b) => b.author.id === ME)
}

/** 我的汇总 ← GET /api/v1/member/blogs/stats */
export function fetchMyStats() {
  const mine = fetchMyBlogs()

  return {
    posts: mine.length,
    like: mine.reduce((s, b) => s + b.stats.like, 0),
    favorite: mine.reduce((s, b) => s + b.stats.favorite, 0),
    block: mine.reduce((s, b) => s + b.stats.block, 0),
    view: mine.reduce((s, b) => s + b.stats.view, 0),
  }
}

/**
 * ← GET /api/v1/comments?target_type=…&target_id=…
 *
 * ⚠️ 这个函数签名是**通用的**：只认 targetType / targetId，不认识"博客"。
 * 论坛落地时原样调用，`targetType` 换成 `forum_post` 即可。
 */
export function fetchComments(targetType: string, targetId: number): Comment[] {
  if (targetType !== 'blog') return []

  return COMMENTS[targetId] ?? []
}

/** 全部评论的扁平列表（审阅用：一眼看清 100 条都挂在谁下面） */
export function fetchAllComments(): { total: number, roots: number, deepest: number, items: Comment[] } {
  const all = [...COMMENT_INDEX.values()]

  return {
    total: all.length,
    roots: all.filter((c) => c.parentId === null).length,
    deepest: all.reduce((m, c) => Math.max(m, c.depth), 0),
    items: all.sort((a, b) => a.id - b.id),
  }
}

/** 当前会员 id —— 用来判"这条是不是我发的" */
export function currentMemberId(): number {
  return ME
}

export const memberPool = MEMBERS

// ---------------------------------------------------------------------------
// 个人主页（作者的博客主页，后期可"装修"）
// ---------------------------------------------------------------------------

export interface AuthorProfile extends Member {
  bio: string
  /** 主页主题色相 —— "装修"的第一期就是让作者挑一个色 */
  hue: number
  /** 覆盖图（未上传为 null，前端渲染占位块，同封面的处理） */
  banner: string | null
  /** 主页是否被作者"装修"过 */
  decorated: boolean
  stats: { posts: number, like: number, favorite: number, view: number }
}

const BIOS = [
  '做点自动化交易的小工具，顺手记点过程。',
  '十年手动，两年自动。踩过的坑比赚过的钱多。',
  '只写实测过的，不写听说的。',
  '白天做工程，晚上做回测。',
  '相信数据，怀疑结论。',
  '把每一次返工都记下来，别白返。',
]

/** ← GET /api/v1/public/authors/{id} */
export function fetchAuthor(id: number): AuthorProfile | null {
  const base = MEMBERS.find((m) => m.id === id)
  if (!base) return null

  const rnd = seeded(id * 977)
  const posts = fetchBlogs().filter((b) => b.author.id === id)

  return {
    ...base,
    bio: pick(rnd, BIOS),
    hue: (id * 47) % 360,
    banner: id % 3 === 0 ? null : coverSvg(seeded(id * 31)),
    decorated: id % 3 !== 0,
    stats: {
      posts: posts.length,
      like: posts.reduce((s, b) => s + b.stats.like, 0),
      favorite: posts.reduce((s, b) => s + b.stats.favorite, 0),
      view: posts.reduce((s, b) => s + b.stats.view, 0),
    },
  }
}

/** ← GET /api/v1/public/authors/{id}/blogs */
export function fetchAuthorBlogs(id: number): Blog[] {
  return fetchBlogs().filter((b) => b.author.id === id && b.status === 'published')
}
