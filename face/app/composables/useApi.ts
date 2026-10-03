/**
 * useApi —— 后端 API 客户端（薄封装）
 *
 * 干什么：
 *   1. 统一 base URL（来自 `runtimeConfig.public.apiBase`）
 *   2. 统一带 Bearer 令牌
 *   3. **统一拆信封**：后端所有出参都是 `{ code, message, data, meta }`，
 *      调用方只关心 `data`，所以这里直接把 `data` 交出去
 *   4. 统一错误形状：非 2xx 时把后端的 `code`/`message` 包成 `ApiError` 抛出，
 *      调用方 `catch` 一个东西就能拿到可展示的文案
 *
 * 边界：
 *   · 只做"搬运"，**不**做鉴权判断、不做业务分支 —— 该不该登录由调用方决定
 *   · 令牌不由这里保管（那是 useAccount 的事），只负责把它加到请求头上
 *
 * 用法：
 *   import { apiRequest, ApiError } from '~/composables/useApi'
 *   const data = await apiRequest<{ token: string }>('/api/v1/member/login', {
 *     method: 'POST',
 *     body: { phone: '11111', password: '11111' },
 *   })
 *
 * @version 0.1.0
 * @since 2026-10-03
 */

/** 后端统一响应体外壳（见 core/docs/modules/00-support.md） */
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

export interface ApiOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  token?: string | null
}

/**
 * 发一个请求，成功返回 `data`，失败抛 `ApiError`。
 *
 * ⚠️ 网络不通（后端没起）时也会抛 `ApiError`，`code` 是 `NETWORK_ERROR`。
 *    调用方按"统一失败"处理即可，不必区分网络错误与业务错误。
 */
export async function apiRequest<T = unknown>(path: string, options: ApiOptions = {}): Promise<T> {
  const config = useRuntimeConfig()

  try {
    const res = await $fetch<Envelope<T>>(path, {
      baseURL: config.public.apiBase as string,
      method: options.method ?? 'GET',
      body: options.body as Record<string, unknown> | undefined,
      headers: options.token ? { Authorization: `Bearer ${options.token}` } : {},
    })

    return res.data
  } catch (error: unknown) {
    const failed = error as { data?: Envelope<unknown>, status?: number }
    const body = failed?.data

    throw new ApiError(
      body?.code ?? 'NETWORK_ERROR',
      body?.message ?? '网络异常，请稍后重试',
      failed?.status ?? 0,
    )
  }
}
