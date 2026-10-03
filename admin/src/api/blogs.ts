/**
 * 博客管理 —— **从 core（PHP）读回来**，不再是本地假数据
 *
 * 对应接口（模块 10 R19）：
 *   GET  /api/v1/admin/blogs               跨会员的博客列表（按状态 / 关键词筛）
 *   POST /api/v1/admin/blogs/{id}/hide     下架 / 恢复
 *
 * ⚠️ 两条接口的门槛**不一样**：
 *   列表要 `blog.read`（owner / admin / moderator / blogger 都有），
 *   下架要 `blog.publish`（**只有 owner 有**）。
 *   这是后端的**安全边界**（见 core/docs/permissions.md 的角色基线表），
 *   前端按 `hasPermission('blog.publish')` 决定按钮显隐只是"不让用户白点"，
 *   真正的拦截在后端 —— 所以即使按钮露出来了，点下去也只会得到 403 提示。
 *
 * @version 0.1.0
 * @since 2026-10-04
 */

import { apiRequest, apiRequestWithMeta } from './client'

/** 博客状态：草稿 / 已发布 / 官方下架 */
export type BlogStatus = 'draft' | 'published' | 'hidden'

/** 附件（按 kind 分流渲染） */
export interface BlogAttachmentItem {
  kind: string
  url: string
  name: string
  size: number
}

/** 一篇博客（与 core 的 `BlogService::toPayload` 逐字对齐） */
export interface BlogItem {
  id: number
  /** 对外标识。详情页 URL 用它，不是自增 id */
  slug: string
  title: string
  excerpt: string
  /** 封面。**草稿可能为 null**（前端渲染占位块，不要留空洞） */
  cover: string | null
  author: {
    /** 自增 id，仅内部使用 */
    id: number
    /** 对外展示用（用户要求：不暴露自增 id） */
    public_id: string | null
    name: string
    /** 头像属 13-profile 模块，本期恒为 null */
    avatar: null
  } | null
  tags: string[]
  stats: {
    like: number
    favorite: number
    /** 被拉黑数。**只有作者自己看得到**，后台也不该在列表上公开它 */
    block: number
    view: number
    comment: number
  }
  /** 当前访问者的互动状态。后台接口不传 viewer，恒为 null */
  mine: Record<string, boolean> | null
  atts: BlogAttachmentItem[]
  /** 是否允许被论坛帖引用（R14） */
  allow_reference: boolean
  status: BlogStatus
  published_at: string | null
  updated_at: string | null
}

/** 列表结果（分页信息来自响应的 `meta`） */
export interface BlogListResult {
  items: BlogItem[]
  page: number
  perPage: number
  total: number
}

/** 列表筛选条件 */
export interface BlogQuery {
  status?: BlogStatus | ''
  keyword?: string
  page?: number
}

/**
 * 拉博客列表。
 *
 * 用法：
 *   const result = await fetchBlogs({ status: 'published', keyword: '点阵', page: 1 })
 *   result.items / result.total
 *
 * 边界/注意：
 *   1. 用 `apiRequestWithMeta` 而不是 `apiRequest` —— 分页信息在响应的 `meta` 里，
 *      后者只交出 `data`，拿不到 total。
 *   2. 空字符串的 `status` 不拼进 query：后端把 `?status=` 当作"没筛"处理还算好，
 *      但有些框架会当成"筛空字符串"，结果永远是空列表。少发一个参数最安全。
 *
 * @version 0.1.0
 * @since 2026-10-04
 */
export async function fetchBlogs(query: BlogQuery = {}): Promise<BlogListResult> {
  const search = new URLSearchParams()

  if (query.status) search.set('status', query.status)
  if (query.keyword?.trim()) search.set('keyword', query.keyword.trim())
  if (query.page && query.page > 1) search.set('page', String(query.page))

  const qs = search.toString()
  const { data, meta } = await apiRequestWithMeta<{ items: BlogItem[] }>(
    `/api/v1/admin/blogs${qs ? `?${qs}` : ''}`,
  )

  return {
    items: data.items ?? [],
    page: Number(meta.page ?? 1),
    perPage: Number(meta.per_page ?? 20),
    total: Number(meta.total ?? 0),
  }
}

/**
 * 下架 / 恢复一篇博客。
 *
 * 用法：
 *   await hideBlog(12, true)    // 下架
 *   await hideBlog(12, false)   // 恢复
 *
 * 边界/注意：
 *   1. 这是**可逆**的操作（published ⇄ hidden），不是删除。
 *      后台刻意不提供删除：软删是作者自己的动作，后台去删会和作者的认知打架
 *      （作者以为文章还在，实际被删了）。
 *   2. 恢复时如果这篇一直没有封面，后端会返回 `BLOG_COVER_REQUIRED`（422）——
 *      调用方直接显示后端给的 message 即可，不用自己翻译错误码。
 *
 * @version 0.1.0
 * @since 2026-10-04
 */
export async function hideBlog(id: number, hidden: boolean): Promise<BlogItem> {
  const data = await apiRequest<{ blog: BlogItem }>(`/api/v1/admin/blogs/${id}/hide`, {
    method: 'POST',
    body: { hidden },
  })

  return data.blog
}
