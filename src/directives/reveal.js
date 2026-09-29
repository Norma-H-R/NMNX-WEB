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
 */

const DEFAULTS = { y: 34, delay: 0, duration: 1.05, stagger: 0.08, selector: null }

const reduced =
  typeof window !== 'undefined' &&
  window.matchMedia('(prefers-reduced-motion: reduce)').matches

let observer = null
const pending = new WeakMap()

function ensureObserver() {
  if (observer) return observer

  observer = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue
        const run = pending.get(entry.target)
        observer.unobserve(entry.target)
        pending.delete(entry.target)
        if (run) run()
      }
    },
    // 元素露出约 16% 时触发，底部再收一点，避免刚探头就播
    { threshold: 0.16, rootMargin: '0px 0px -8% 0px' },
  )

  return observer
}

export default {
  mounted(el, binding) {
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

  unmounted(el) {
    if (observer) observer.unobserve(el)
    pending.delete(el)
  },
}
