/**
 * HTTP 客户端 —— admin 前端与 core（PHP）后端之间**唯一**的口子
 *
 * 干什么：
 *   1. 统一 base URL（`VITE_API_BASE`，默认本地 core 的 `php artisan serve`）
 *   2. 统一带 `Authorization: Bearer <token>`
 *   3. **统一拆信封**：core 所有出参都是 `{ code, message, data, meta }`，
 *      调用方只关心 `data`，这里直接交出去
 *   4. 统一错误形状：非 2xx 把后端的 `code` / `message` 包成 `ApiError`；
 *      后端给的 `message` 已经是人话（中文），界面可以直接显示
 *
 * 令牌存哪：
 *   localStorage 的 `nmnx.admin.session`，形如 `{ token, expiresAt }`，默认 **24 小时**
 *   （用户定的"服务端令牌 24 小时一换"；本地这份只是让它提前失效，真正的强约束要设
 *   core 的 `SANCTUM_EXPIRATION`）。
 *
 * ⚠️ 只做搬运，不判权限、不做业务分支。
 *
 * @version 0.1.0
 * @since 2026-10-03
 */

/** core 的统一响应体外壳 */
interface Envelope<T> {
  code: string
  message: string
  data: T
  meta: unknown
}

/** 调用方拿到的错误：`code` 可判断，`message` 可直接显示 */
export class ApiError extends Error {
  readonly code: string
  readonly httpStatus: number

  constructor(code: string, message: string, httpStatus: number) {
    super(message)
    this.name = 'ApiError'
    this.code = code
    this.httpStatus = httpStatus
  }
}

const TOKEN_KEY = 'nmnx.admin.session'

/** 本地会话有效期：24 小时 */
const SESSION_TTL = 24 * 60 * 60 * 1000

/** API 地址。本地开发指向 core；部署时用 VITE_API_BASE 覆盖 */
const BASE: string = (import.meta.env.VITE_API_BASE as string | undefined) ?? 'http://127.0.0.1:8000'

interface Session {
  token: string
  expiresAt: number
}

/** 读本地会话；没有、格式坏、或已过期都返回 null（顺手把过期的那条清掉） */
function readSession(): Session | null {
  try {
    const raw = window.localStorage.getItem(TOKEN_KEY)
    if (!raw) return null

    const parsed = JSON.parse(raw) as Session
    if (!parsed?.token || typeof parsed.expiresAt !== 'number') return null

    if (parsed.expiresAt <= Date.now()) {
      window.localStorage.removeItem(TOKEN_KEY)
      return null
    }

    return parsed
  } catch {
    return null
  }
}

/** 取当前令牌（没登录 / 过期返回 null） */
export function getToken(): string | null {
  return readSession()?.token ?? null
}

/** 登录成功后保存令牌（默认 24 小时） */
export function saveToken(token: string): void {
  const session: Session = { token, expiresAt: Date.now() + SESSION_TTL }
  window.localStorage.setItem(TOKEN_KEY, JSON.stringify(session))
}

/** 清掉本地会话（登出、或接口返回 401 时调） */
export function clearToken(): void {
  window.localStorage.removeItem(TOKEN_KEY)
}

export interface ApiOptions {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'
  body?: unknown
  /** 显式指定令牌；不传则用本地会话里的那个 */
  token?: string | null
}

/**
 * 发一个请求，成功返回 `data`，失败抛 `ApiError`。
 *
 * 用法：
 *   const data = await apiRequest<{ token: string }>('/api/v1/admin/login', {
 *     method: 'POST',
 *     body: { username, password },
 *   })
 *
 * 边界/注意：
 *   1. 网络不通（后端没起）也会抛 `ApiError`，`code` 是 `NETWORK_ERROR` ——
 *      调用方按"统一失败"处理即可，不必区分网络错与业务错。
 *   2. 收到 401 会**顺手清掉本地令牌**：令牌失效了还留着它，
 *      界面会一直以为自己是登录态。
 */
export async function apiRequest<T = unknown>(path: string, options: ApiOptions = {}): Promise<T> {
  const { data } = await apiRequestWithMeta<T>(path, options)

  return data
}

/**
 * 同 `apiRequest`，但**连 `meta` 一起返回**。
 *
 * 为什么需要它：core 的约定是"业务数据放 data、分页等附加信息放 meta"
 * （见 `core/docs/modules/00-support.md`）。列表接口的分页信息就在 meta 里，
 * 而 `apiRequest` 只交出 data —— 那种场景下分页就拿不到了。
 * 需要分页的调用方用这个；其余继续用 `apiRequest`（不必关心 meta）。
 */
export async function apiRequestWithMeta<T = unknown>(
  path: string,
  options: ApiOptions = {},
): Promise<{ data: T, meta: Record<string, unknown> }> {
  const token = options.token !== undefined ? options.token : getToken()

  const headers: Record<string, string> = { Accept: 'application/json' }
  if (options.body !== undefined) headers['Content-Type'] = 'application/json'
  if (token) headers.Authorization = `Bearer ${token}`

  let response: Response

  try {
    response = await fetch(BASE + path, {
      method: options.method ?? 'GET',
      headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    })
  } catch {
    throw new ApiError('NETWORK_ERROR', '连不上后端服务，请确认 core 已启动', 0)
  }

  let body: Envelope<T> | null = null

  try {
    body = (await response.json()) as Envelope<T>
  } catch {
    body = null
  }

  if (!response.ok) {
    if (response.status === 401) {
      clearToken()

      /*
       * 令牌失效 → 广播一个事件，由入口（main.ts）把人送回登录页。
       *
       * 为什么不在这个模块里直接 `router.push`：
       *   `client.ts` 是**最底层**的搬运工，让它依赖 router 会把"路由"和"HTTP"
       *   绑在一起（还要担心 router ↔ views ↔ client 的循环引用）。
       *   派个事件出去，谁想监听谁监听 —— 换 UI 框架也不用改这里。
       *
       * ⚠️ 不广播的后果是真实的：令牌失效后页面只会**静静显示空列表**
       *   （权限页/角色页都这样），让人以为"数据没了"，其实是没登录。
       */
      window.dispatchEvent(new CustomEvent('nmnx:unauthenticated', { detail: body?.message }))
    }

    if (response.status === 403) {
      /*
       * **无权限** → 广播出去，由入口负责"弹窗 + 重拉权限列表"。
       *
       * 为什么不用 401 那套（跳登录页）：403 的人**是登录着的**，
       * 只是这件事没权限 —— 把他踢回登录页既没道理也没用。
       *
       * 为什么还要重拉权限列表：他可能**刚刚被收走**了权限，
       * 而界面上那个入口还在（前端列表是登录那一刻的快照）。
       * 不重拉的话，他会一直点、一直弹窗，比直接看不见更烦。
       *
       * 后端给的 message 已经带具体权限名（"你没有「删除帖子」权限"），
       * 直接拿去显示，不要自己再拼一句笼统的"操作失败"。
       */
      window.dispatchEvent(new CustomEvent('nmnx:forbidden', { detail: body?.message }))
    }

    throw new ApiError(
      body?.code ?? 'HTTP_' + response.status,
      body?.message ?? `请求失败（HTTP ${response.status}）`,
      response.status,
    )
  }

  return {
    data: body?.data as T,
    meta: (body?.meta ?? {}) as Record<string, unknown>,
  }
}
