import { ref } from 'vue'

/**
 * 页面级过渡遮罩（"闸门"）的全局状态机 —— 与 admin 的 usePageVeil 是同一套。
 *
 * 为什么用模块级单例而不是 useState：
 *   它是纯视觉开关，只有 4 个相位、生命周期就夹在一次换页之间；
 *   而且必须在换页的同一瞬间被"旧页面（发动）"和"App 外壳（渲染遮罩）"
 *   同时读到 —— 模块单例最直接，也省掉了跨组件的时序问题。
 *   注意它只在客户端被 transitionTo 推动（SSR 期间永远是 idle，
 *   所以不存在跨请求串状态的问题）。
 *
 * 时序是这个东西唯一容易翻车的地方，错一拍就会在换页那一刻闪出旧页/白底：
 *
 *   covering  闸门合拢（560ms）
 *   covered   屏被完全遮住 —— 此刻换页，用户看不到任何跳变
 *   …swap()  换路由，再给新页面留 120ms 完成首帧布局，否则揭开时会看到"半成品"
 *   revealing 闸门反向打开（720ms）
 *   idle      归位，等下一次
 */

export type VeilPhase = 'idle' | 'covering' | 'covered' | 'revealing'

// 关掉动效的用户不该被晾在原地干等一秒多，时长压到几乎瞬时（视觉由 CSS 一起降级）
const reduced =
  typeof window !== 'undefined' && typeof window.matchMedia === 'function'
    ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
    : false

const COVER_MS = reduced ? 90 : 560
const REVEAL_MS = reduced ? 90 : 720
const SETTLE_MS = reduced ? 20 : 120

const phase = ref<VeilPhase>('idle')

const wait = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms))

/**
 * 盖上遮罩 → 换页 → 揭开。
 *
 * "换页"这件事做成回调传进来、由遮罩精确地包住它，而不是去监听路由变化 ——
 * 监听的话新页面会先渲染出来闪一帧，然后遮罩才盖上，那一帧就是穿帮。
 *
 * 用法：await transitionTo(() => navigateTo('/account'))
 */
export async function transitionTo(swap: () => unknown | Promise<unknown>) {
  if (phase.value !== 'idle') return // 过渡中再点不给进，避免两条时序互相踩

  phase.value = 'covering'
  await wait(COVER_MS)
  phase.value = 'covered'

  await swap()
  await wait(SETTLE_MS)

  phase.value = 'revealing'
  await wait(REVEAL_MS)
  phase.value = 'idle'
}

export function usePageVeil() {
  return { phase }
}
