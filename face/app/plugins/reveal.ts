/**
 * v-reveal —— 滚动进入视口时做一次入场动画。
 *
 *   v-reveal                             单元素淡入上浮
 *   v-reveal="{ delay: .2, y: 46 }"      自定义
 *   v-reveal="{ selector: '.item', stagger: .08 }"
 *                                        容器进入时，子元素依次错开入场
 *
 * 纯 CSS 过渡实现，不引入任何动画库。指令只做三件事：
 *   1. 挂上 data-reveal，并把 y / duration / delay 写进 --rv-* 变量；
 *   2. 元素进入视口后加 .is-revealed；
 *   3. 过渡播完把属性摘干净。
 * 真正的动画在 main.css 的 [data-reveal] 规则里。
 *
 * 第 3 步不能省：留着 transform 会永久占住这个属性，卡片 :hover 的位移就没了。
 *
 * 只播一次，播完即解除观察；prefers-reduced-motion 下不做动画、直接呈现。
 *
 * 服务端渲染安全：写成通用插件而不是 .client.ts —— SSR 渲染时会调用指令的
 * getSSRProps，指令没注册会让整页 500。浏览器 API 全部收在 mounted 里。
 */

const DEFAULTS = { y: 34, delay: 0, duration: 1.05, stagger: 0.08, selector: null }

let observer: IntersectionObserver | null = null
const pending = new WeakMap<Element, () => void>()

function ensureObserver() {
  if (observer) return observer

  observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue
        const run = pending.get(entry.target)
        observer!.unobserve(entry.target)
        pending.delete(entry.target)
        if (run) run()
      }
    },
    // 元素露出约 16% 时触发，底部再收一点，避免刚探头就播
    { threshold: 0.16, rootMargin: '0px 0px -8% 0px' },
  )

  return observer
}

export default defineNuxtPlugin((nuxtApp) => {
  nuxtApp.vueApp.directive('reveal', {
    // 服务端渲染阶段不做任何事，但必须声明：SSR 会调它来收集指令产生的属性
    getSSRProps() {
      return {}
    },

    mounted(el: HTMLElement, binding) {
      // 能走到这里说明已经在客户端，浏览器 API 可以放心用
      const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches
      const opts = { ...DEFAULTS, ...(binding.value || {}) }

      const targets = (
        opts.selector ? Array.from(el.querySelectorAll(opts.selector)) : [el]
      ) as HTMLElement[]

      if (!targets.length) return

      let lastEnd = opts.duration

      targets.forEach((t, i) => {
        const delay = opts.delay + i * opts.stagger
        lastEnd = Math.max(lastEnd, delay + opts.duration)

        t.setAttribute('data-reveal', '')
        t.style.setProperty('--rv-y', `${opts.y}px`)
        t.style.setProperty('--rv-duration', `${opts.duration}s`)
        t.style.setProperty('--rv-delay', `${delay}s`)
      })

      const teardown = () => {
        for (const t of targets) {
          t.removeAttribute('data-reveal')
          t.classList.remove('is-revealed')
          t.style.removeProperty('--rv-y')
          t.style.removeProperty('--rv-duration')
          t.style.removeProperty('--rv-delay')
        }
      }

      if (reduced) {
        // 不做动画，也不留任何痕迹
        teardown()
        return
      }

      pending.set(el, () => {
        for (const t of targets) t.classList.add('is-revealed')
        // 等最后一个元素播完再清理，把 transform 让回给 :hover
        window.setTimeout(teardown, lastEnd * 1000 + 80)
      })

      ensureObserver().observe(el)
    },

    unmounted(el: HTMLElement) {
      if (observer) observer.unobserve(el)
      pending.delete(el)
    },
  })
})
