/**
 * 对外公开 ID 的格式规则。
 *
 * 为什么不把数据库自增 id 直接摆到界面上：
 *   1) 风格不统一 —— 一个裸数字「17」夹在 NMNX 这套视觉里很突兀，
 *      换成「NMX-U-000017」才和其它标识（版本号、机器码）是一个语系；
 *   2) 会暴露业务量 —— "注册第 17 个用户"这种事没必要被看出来。
 *
 * 规则：前缀 + 六位补零，与数据库 id 一一对应（可以反查，排障时有用）。
 * 前缀里的 U 表示 User —— 以后产品、授权码、机器码各用各的前缀，一眼能分清实体类型。
 *
 * ⚠️ 正式数据一律用后端返回的 publicId 字段，**不要在前端现算**：
 *    后端将来可能换成随机段 + 校验位（那样更难被遍历）。
 *    下面这两个函数只用于本地假数据，以及后端字段缺失时的兜底展示。
 */

export const USER_ID_PREFIX = 'NMX-U'
export const USER_ID_PAD = 6

/** 数据库 id → 公开 ID（兜底用，正式数据请用后端返回的 publicId） */
export function derivePublicId(dbId: number, prefix: string = USER_ID_PREFIX): string {
  return `${prefix}-${String(dbId).padStart(USER_ID_PAD, '0')}`
}

/**
 * 公开 ID → 数据库 id。
 * 解析不出来返回 null（不要抛异常 —— 它多用于展示层的容错分支）。
 */
export function parsePublicId(publicId: string): number | null {
  const digits = /(\d+)\s*$/.exec(publicId.trim())?.[1]
  if (!digits) return null

  const value = Number(digits)
  return Number.isFinite(value) ? value : null
}

/**
 * 由公开 ID 派生一个稳定的色相（0~359）。
 * 用于没有头像时的兜底色块 —— 同一个用户每次刷新颜色都一样，
 * 换个人就换个色，既保证"统一风格"又能一眼区分。
 */
export function hueFromId(publicId: string): number {
  let hash = 0
  for (let i = 0; i < publicId.length; i += 1) {
    hash = (hash * 31 + publicId.charCodeAt(i)) % 360
  }
  return hash
}

/** 取昵称的首字作为兜底头像文字：中文取第一个字，英文取首字母大写 */
export function initialOf(nickname: string): string {
  const trimmed = nickname.trim()
  if (!trimmed) return '?'

  const first = trimmed.slice(0, 1)
  return /[a-z]/i.test(first) ? first.toUpperCase() : first
}
