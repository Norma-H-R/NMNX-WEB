import gsap from 'gsap'

/**
 * v-reveal —— 滚动进入视口时做一次入场动画。
 *
 *   v-reveal                             单元素淡入上浮
 *   v-reveal="{ delay: .2, y: 46 }"      自定义
 *   v-reveal="{ selector: '.item', stagger: .08 }"
 *                                        容器进入时，子元素依次错开入场
 *
 * 只播一次，播完即解除观察；prefers-reduced-motion 下直接显示不做动画。
 *
 * 从 Vite 工程平移过来，改动有两处：
 *   1. 指令对象改成在 Nuxt 插件里注册；
 *   2. 不写成 .client.ts —— 服务端渲染时 Vue 依然会去找指令的 getSSRProps，
 *      如果指令压根没注册，拿到 undefined 就会把整页渲染搞崩。所以这里用通用
 *      插件，只在真正碰浏览器 API 的地方做隔离（mounted 只会在客户端执行）。
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
    // 服务端渲染阶段不做任何事，但必须声明：SSR 会调用它来收集指令产生的属性
    getSSRProps() {
      return {}
    },

    mounted(el: HTMLElement, binding) {
      // 能走到这里说明已经在客户端，浏览器 API 可以放心用
      const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches

      const opts = { ...DEFAULTS, ...(binding.value || {}) }

      const targets = opts.selector
        ? Array.from(el.querySelectorAll(opts.selector))
        : [el]

      if (!targets.length) return

      if (reduced) {
        gsap.set(targets, { opacity: 1, y: 0 })
        return
      }

      gsap.set(targets, { opacity: 0, y: opts.y })

      pending.set(el, () => {
        gsap.to(targets, {
          opacity: 1,
          y: 0,
          duration: opts.duration,
          delay: opts.delay,
          stagger: opts.stagger,
          ease: 'power3.out',
          // 收尾清掉 transform，避免和 hover 的 transform 打架
          clearProps: 'transform',
        })
      })

      ensureObserver().observe(el)
    },

    unmounted(el: HTMLElement) {
      if (observer) observer.unobserve(el)
      pending.delete(el)
    },
  })
})
