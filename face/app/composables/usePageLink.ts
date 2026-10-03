import { transitionTo } from '~/composables/usePageVeil'

/**
 * 站内跳转的统一入口。
 *
 * 为什么要收在一处：这套「合拢屏 → 换页 → 揭开」的闸门过渡，必须由**发起跳转的
 * 那一方**包住。漏掉任何一处，就会变成"有的地方有动画、有的地方没有"，而且没包的
 * 那处还会穿帮 —— 之前详情页的返回就没包，换页那一下新页面先以"没内容"的状态渲了
 * 一帧，浏览器顺势把滚动甩到底，等内容铺开再回弹。
 *
 * 两条规则也一并收在这里，省得每个页面各写一遍：
 *   · **只接管普通左键**：中键 / Ctrl / Shift / Alt + 点击仍交给浏览器（新标签打开）。
 *     这也意味着调用处的 `<a href>` 要留着 —— 它是禁用 JS 时的唯一出路。
 *   · 过渡进行中重复点击由 transitionTo 自己挡掉（phase !== idle 直接返回），
 *     这层不用再判断。
 *
 * 用法：`<a href="/notices" @click="goWithVeil($event, '/notices')">`
 */
export function goWithVeil(e: MouseEvent, path: string) {
  if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return

  e.preventDefault()
  transitionTo(() => navigateTo(path))
}
