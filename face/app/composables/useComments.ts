/**
 * 评论数据层（**当前是假数据**）—— 一个**跟业务无关**的通用层。
 *
 * ⚠️ 它是独立系统，不是博客的一部分（见 core/docs/modules/10-blog.md 决策 9）。
 *    博客、论坛、以后的官方文章，全都走这里同一个入口 —— 区别只在 `targetType`：
 *
 *      fetchComments('blog', 12)        博客
 *      fetchComments('forum_post', 88)  论坛帖
 *
 * 接口（待后端实现）：`GET /api/v1/comments?target_type=…&target_id=…`
 *
 * ⚠️ 假数据一律**确定性生成**：同一个 target 不论在服务端还是浏览器里算，
 *    结果必须一模一样，否则 hydration 直接崩。
 *    `seeded()` 的种子由 `targetType:targetId` 拼出来，所以 "谁的第几条评论"
 *    永远是同一批 —— 也不需要预先造好全站评论。
 */

export interface Commenter {
  id: number
  name: string
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
  user: Commenter
  body: string
  stats: CommentStats
  createdAt: string
  children: Comment[]
  /**
   * 当前登录会员对这条的操作状态。
   * 真接口里它叫 `mine`；这里挂成 `mineState`，因为接口来之前是**本地改的**
   * （乐观更新），得有个地方放得住。
   */
  mineState?: { liked?: boolean, disliked?: boolean, favorited?: boolean }
}

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

function hash(str: string): number {
  let h = 2166136261

  for (let i = 0; i < str.length; i++) {
    h ^= str.charCodeAt(i)
    h = Math.imul(h, 16777619)
  }

  return h >>> 0
}

type Rnd = () => number

const pick = <T,>(rnd: Rnd, list: readonly T[]): T => list[Math.floor(rnd() * list.length)]!
const int = (rnd: Rnd, min: number, max: number) => min + Math.floor(rnd() * (max - min + 1))

// ---------------------------------------------------------------------------
// 素材
// ---------------------------------------------------------------------------
const COMMENTERS: Commenter[] = [
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
]

const BODIES = [
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
  '两个账户跑同一套信号，结果能差这么多？',
  '看完了，准备明天上测试账户试一下。',
  '这条我在官方仓库也看到过类似的 issue。',
  '链接挂了，能补一下吗',
]

const PAD6 = (n: number) => String(n).padStart(6, '0')

/**
 * 不同 target 的评论数量差很多，好把"长帖 / 冷帖"都试到。
 *
 * ⚠️ 用户明确要求：**至少 50 条**，而且要能看出"一个评论下面挂多个回复"。
 *    所以头几篇博客 / 头几个帖子给足量（50+），后面的零散几条 ——
 *    冷热对比也顺便试到了。
 */
function countOf(targetType: string, targetId: number): number {
  if (targetType === 'blog') {
    return targetId <= 6 ? 56 - targetId * 2 : (targetId % 5) + 2
  }

  return targetId <= 4 ? 58 - targetId * 3 : (targetId % 12) + 3
}

/**
 * 造一棵评论树。
 *
 * 直接评论的与回复的混在一起（用户明确要求"既有直接回复的、也有回复评论的"）；
 * 回复偏向从**最近几条**里挑爹，这样自然串成一串一串的对话，
 * 而不是均匀撒开 —— Reddit 那种长链就是这么来的。
 */
function build(targetType: string, targetId: number): Comment[] {
  const rnd = seeded(hash(`${targetType}:${targetId}`))
  const total = countOf(targetType, targetId)
  const roots: Comment[] = []
  const flat: Comment[] = []
  let nextId = targetId * 1000

  for (let i = 0; i < total; i++) {
    const id = ++nextId

    let parent: Comment | null = null

    // 约 6 成挂到已有评论下（= 回复评论），其余是直接评论（顶层）。
    // 用户要的就是这两种混在一起。
    if (i >= 2 && rnd() < 0.62) {
      // 只在 6 层以内挑爹；再深前端就折叠了
      const pool = flat.filter((c) => c.depth < 6)

      if (pool.length) {
        // **刻意偏向"已经被回复过"的父评论** —— 这样自然会形成
        // "一个评论下面挂好几个回复"的小簇，而不是每条父都只分到一个孩子
        const hot = pool.filter((c) => c.stats.reply > 0)
        const src = hot.length && rnd() < 0.55 ? hot : pool.slice(-10)
        parent = src[Math.floor(rnd() * src.length)]!
      }
    }

    const comment: Comment = {
      id,
      parentId: parent ? parent.id : null,
      depth: parent ? parent.depth + 1 : 1,
      path: '',
      user: pick(rnd, COMMENTERS),
      body: pick(rnd, BODIES),
      stats: {
        like: int(rnd, 0, 58),
        dislike: int(rnd, 0, 7),
        favorite: int(rnd, 0, 16),
        reply: 0,
      },
      createdAt: new Date(Date.UTC(2026, 9, 4) - int(rnd, 0, 26) * 3_600_000)
        .toISOString()
        .replace('.000Z', '+00:00'),
      children: [],
      mineState: {
        liked: rnd() < 0.2,
        disliked: rnd() < 0.06,
        favorited: rnd() < 0.08,
      },
    }

    comment.path = parent ? `${parent.path}${PAD6(id)}/` : `${PAD6(id)}/`

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

// ---------------------------------------------------------------------------
// 缓存：同一个 target 只算一次（结果确定，所以缓存是安全的）
// ---------------------------------------------------------------------------
const cache = new Map<string, Comment[]>()

/** ← GET /api/v1/comments?target_type=…&target_id=… */
export function fetchComments(targetType: string, targetId: number): Comment[] {
  const key = `${targetType}:${targetId}`

  if (!cache.has(key)) cache.set(key, build(targetType, targetId))

  return cache.get(key)!
}

/** 整棵树有多少条（列表页显示"N 条评论"用） */
export function countComments(targetType: string, targetId: number): number {
  let n = 0
  const walk = (list: Comment[]) => list.forEach((c) => (n += 1, walk(c.children)))
  walk(fetchComments(targetType, targetId))

  return n
}

/** 当前登录会员 id（假数据里固定 2 号；真实环境来自 useAccount） */
export function currentMemberId(): number {
  return 2
}
