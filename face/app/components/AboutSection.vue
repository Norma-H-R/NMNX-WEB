<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * 理念区块 —— 按 Inspira UI「Card Stack」的真实源码移植。
 *
 * 原组件（registry.inspira-ui.com/card-stack.json）由 4 个文件组成，依赖 motion-v：
 *   CardStack.vue      useScroll({ offset: ['start start','end end'], target })
 *                      → 提供 scrollYProgress（0~1）
 *   CardStackItem.vue  外层 sticky top-0 h-full；内层 top: 5 + i*3 %、
 *                      scale: useTransform(progress, [i/N, 1], [1, 1-(N-i)*m])
 *   CardStackContext   透传 { progress, scaleMultiplier, totalCards }
 *
 * 机制就一句话：**每张卡吸顶 + 按滚动进度逐张缩小**，于是前面的卡在顶部露出一条边，
 * 后面的卡一张张盖上来。本项目零依赖，把 motion 的两个原语等价替换即可：
 *   useScroll  → 自己算 progress = (scrollY - stackTop) / (stackHeight - viewportH)
 *   useTransform → 每个卡自己算 scale（原式的输入/输出区间原样保留）
 *
 * 原 demo 是在一个固定高度的 overflow-auto 容器里滚；这里改成跟随页面滚动，
 * 对整站更自然。scaleMultiplier 默认 0.03 与原组件一致。
 */

const SCALE_MULTIPLIER = 0.03 // 原组件默认值

const cards = [
  {
    no: '01',
    title: '两端协作',
    desc: '主控负责决策与下单，跟随端通过命名管道直驱，把指令送到每一台终端。',
    tag: '主控 + 跟随端',
  },
  {
    no: '02',
    title: '毫秒级直驱',
    desc: '命名管道直连终端，跳过轮询与文件落地，指令以毫秒级送达。',
    tag: '命名管道',
  },
  {
    no: '03',
    title: '链路在本地',
    desc: '全程本地完成，不依赖第三方中转。数据与指令不离开自己的机器。',
    tag: '本地链路',
  },
  {
    no: '04',
    title: '离线授权',
    desc: '离线激活码绑定账号、经纪商与有效期；一次编译即可分发给不同客户。',
    tag: '离线激活码',
  },
]

const total = cards.length
const stackRef = ref(null)
const boxes = ref([]) // 每张卡的内层（承接 scale）

// 预先算好的几何：避免每帧 getBoundingClientRect 触发同步布局
let stackTop = 0
let stackRange = 1
let raf = 0
let io = null
let live = false

function measure() {
  const el = stackRef.value
  if (!el) return
  const r = el.getBoundingClientRect()
  stackTop = r.top + window.scrollY
  stackRange = Math.max(1, r.height - window.innerHeight)
}

const clamp01 = (v) => (v < 0 ? 0 : v > 1 ? 1 : v)

// 原式：scale = useTransform(progress, [i/N, 1], [1, scaleTo])
//       scaleTo = 1 - (N - i) * scaleMultiplier
function apply() {
  raf = 0
  if (!live) return
  const p = clamp01((window.scrollY - stackTop) / stackRange)
  for (let i = 0; i < total; i++) {
    const el = boxes.value[i]
    if (!el) continue
    const from = i / total
    const t = clamp01((p - from) / (1 - from || 1))
    const scaleTo = 1 - (total - i) * SCALE_MULTIPLIER
    const scale = 1 + (scaleTo - 1) * t
    el.style.transform = `scale(${scale.toFixed(4)})`
  }
}

function onScroll() {
  if (raf || !live) return
  raf = requestAnimationFrame(apply)
}

function onResize() {
  measure()
  onScroll()
}

onMounted(() => {
  measure()
  // 用 IntersectionObserver 管住活跃区间：不在附近就完全不参与滚动计算
  if (typeof IntersectionObserver !== 'undefined' && stackRef.value) {
    io = new IntersectionObserver(
      (entries) => {
        live = entries[entries.length - 1].isIntersecting
        if (live) onScroll()
      },
      { rootMargin: '40% 0px' },
    )
    io.observe(stackRef.value)
  } else {
    live = true
  }
  window.addEventListener('scroll', onScroll, { passive: true })
  window.addEventListener('resize', onResize, { passive: true })
  apply()
})

onBeforeUnmount(() => {
  if (raf) cancelAnimationFrame(raf)
  if (io) io.disconnect()
  window.removeEventListener('scroll', onScroll)
  window.removeEventListener('resize', onResize)
})
</script>

<template>
  <section id="about" class="section about">
    <div class="container">
      <div class="about__head" v-reveal="{ selector: '.rv', stagger: 0.1 }">
        <p class="eyebrow rv">理念</p>
        <h2 class="title rv">
          把交易执行<br />
          做到<em>安静而准确</em>
        </h2>
      </div>
    </div>

    <!-- 卡片堆叠：整段占多屏，每张卡吸顶，按滚动进度逐张缩小。
         放进 .container：卡片宽度与上方正文栏一致（1180 内容宽），
         否则会既比正文窄、左边又对不齐。 -->
    <div class="container">
      <div ref="stackRef" class="stack">
      <div v-for="(c, i) in cards" :key="c.no" class="stack__item">
        <div
          :ref="(el) => (boxes[i] = el)"
          class="stack__box"
          :style="{ top: `${5 + i * 3}%` }"
        >
          <article class="stack__card" :class="`stack__card--${i + 1}`">
            <header class="stack__top">
              <span class="stack__no">{{ c.no }}</span>
              <span class="stack__tag">{{ c.tag }}</span>
            </header>

            <div class="stack__body">
              <h3 class="stack__title">{{ c.title }}</h3>
              <p class="stack__desc">{{ c.desc }}</p>
            </div>

              <span class="stack__glow" aria-hidden="true" />
            </article>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
/* 末卡到下一块标题的间距，比全站 .section 的标准下内边距（clamp(96px,15vh,190px)）要小。
   原因：这一块末尾是整叠卡片，末卡下方本来就自带 96px 的布局余量（卡片高 74%、顶偏 14%），
   再叠一整个 section 级下内边距就重复了 —— 实测会是 96 + 121 + 121 = 338，
   比全站其它区块之间的 293px 还大。
   这里按 9.5vh 给（标准值的 0.63 倍），实测让这段间距落在 293px，与"能力→联系"对齐。 */
.about {
  padding-bottom: clamp(64px, 9.5vh, 110px);
}

.about__head {
  max-width: 720px;
}

/* ---------------------------- 卡片堆叠 ---------------------------- */
/* 整段高度 = 卡片数 × 每张一屏；滚动空间就是它的高度减去视口 */
.stack {
  position: relative;
  margin-top: clamp(36px, 5vw, 64px);
  /* 这里原来有一段 padding-bottom: 42vh 的"尾巴"，别再加回来。
     它看着像是给吸顶留的滚动空间，其实不是：吸顶元素的活动范围被限制在父元素的
     **内容盒**内，padding 在内容盒之外，撑不出任何吸顶行程 —— 实测 .stack 高 3558
     = 卡片 4×805 + 338，那 338 就是它，纯空白。它唯一的作用是把 apply() 里的
     stackRange（= r.height - innerHeight，含 padding）一起拉长，让缩放动画慢 12%。
     代价是理念区末尾要多滚近半屏空屏，与下一块的间距达到 685px（同页其它区块 293px）。
     删掉后：stackRange 按内容高度算（2415），各卡仍在滚进视野时依次缩放到终值，
     而且 p = 1 恰好落在"四张卡叠齐"那一刻，之后整叠一起滚出，不会先散架再空一屏。 */
}

/* 每张卡一屏高、吸顶；DOM 顺序靠后的盖在上面 */
.stack__item {
  position: sticky;
  top: 0;
  height: 100vh;
}

/* 内层承接缩放入场。top 由内联样式给（原组件是 5 + i*3 %），
   让被埋住的卡在顶部露出一条边 */
.stack__box {
  position: relative;
  height: 100%;
  transform-origin: top center;
  will-change: transform;
}

.stack__card {
  position: relative;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  /* 吃满 .container 的内容宽（与上方正文栏左右对齐），不再自己限宽 */
  width: 100%;
  height: 74%;
  margin: 0 auto;
  padding: clamp(28px, 3.4vw, 52px);
  border: 1px solid var(--line-strong);
  border-radius: 20px;
  background: linear-gradient(160deg, rgba(20, 24, 38, 0.96), rgba(9, 11, 20, 0.98));
  overflow: hidden;
}

/* 每张卡换一缕强调色，呼应原来的轨道配色 */
.stack__card--1 .stack__glow {
  background: radial-gradient(58% 62% at 86% 8%, rgba(110, 231, 255, 0.14), transparent 70%);
}
.stack__card--2 .stack__glow {
  background: radial-gradient(58% 62% at 86% 8%, rgba(167, 139, 250, 0.14), transparent 70%);
}
.stack__card--3 .stack__glow {
  background: radial-gradient(58% 62% at 86% 8%, rgba(242, 209, 141, 0.12), transparent 70%);
}
.stack__card--4 .stack__glow {
  background: radial-gradient(58% 62% at 86% 8%, rgba(110, 231, 255, 0.12), transparent 70%);
}

.stack__glow {
  position: absolute;
  inset: 0;
  pointer-events: none;
}

.stack__top {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.stack__no {
  font-size: 12px;
  letter-spacing: 0.3em;
  color: var(--muted);
}

.stack__tag {
  padding: 5px 13px;
  border: 1px solid var(--line);
  border-radius: 99px;
  font-size: 12px;
  letter-spacing: 0.06em;
  color: var(--muted);
}

.stack__body {
  position: relative;
  padding-bottom: clamp(4px, 1vw, 12px);
}

.stack__title {
  font-size: clamp(30px, 4.4vw, 62px);
  font-weight: 500;
  line-height: 1.1;
  letter-spacing: 0.01em;
  color: #fff;
}

.stack__desc {
  margin-top: clamp(14px, 1.8vw, 24px);
  max-width: 46ch;
  font-size: clamp(15px, 1.25vw, 19px);
  line-height: 1.9;
  color: var(--text-dim);
}

/* ---------------------------- 降级 ---------------------------- */
/* 关了动效就别做堆叠了：改成普通竖排卡片，内容照样读得完 */
@media (prefers-reduced-motion: reduce) {
  .stack {
    padding-bottom: 0;
  }
  .stack__item {
    position: static;
    height: auto;
  }
  .stack__box {
    top: 0 !important;
    transform: none !important;
  }
  .stack__card {
    height: auto;
    margin-bottom: 20px;
  }
}

@media (max-width: 640px) {
  .stack__card {
    height: 78%;
    border-radius: 16px;
  }
}
</style>
