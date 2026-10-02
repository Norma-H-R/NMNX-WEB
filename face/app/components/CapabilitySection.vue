<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * 能力区块 —— 按 Inspira UI「Design Testimonials」的真实源码移植。
 *
 * 原组件（registry.inspira-ui.com/design-testimonials.json）依赖 motion-v + Tailwind，
 * 本项目零依赖 + 手写 CSS，所以按它的结构一对一翻译，动效等价替换：
 *
 *   原实现                                 这里
 *   useMotionValue + useSpring(k200/c25)    手写同参数弹簧（半隐式欧拉积分）
 *   useTransform([-200,200] → [-20,20])     同一个映射，超出夹紧
 *   <AnimatePresence mode="wait">           <Transition mode="out-in"> + CSS 过渡
 *   逐词 variants(delay i*0.05, rotateX90)  每个词一条 keyframes，靠 --i 错开
 *   motion 的 infinite marquee              纯 CSS keyframes 跑马灯
 *
 * 原组件的视觉重心是那个 6% 透明度的**超大序号**，磁性视差主要作用在它身上；
 * 左侧竖排标题 + 竖向进度条、公司胶囊、大字引用 + 逐词翻转、作者行 + 展开线、
 * 底部公司跑马灯，都照原样保留。
 *
 * 数据从原来三张卡片的文案改写成原组件的四段式：
 *   quote   ← 描述（大字引用）      author ← 标题（主控 / 跟随端 / 授权）
 *   company ← 首个标签（顶部胶囊）   role   ← 次个标签
 *
 * 布局改造成「左文字 / 右图片」两列，并加两层视差（都是纯手写，不引依赖）：
 *   滚动视差   两列反向位移，文字慢、图片快 —— 脚本按 .dt 穿过视口的进度写
 *              --cap-text-y / --cap-media-y；
 *   图片入场   同一支进度驱动 "透明缩小 → 逐渐放大"（--cap-media-o / --cap-media-s），
 *              换图时 img 自己再缩放一次，父子变换相乘互不打架。
 * 每项配一张图，见下方 items[].image（生成图裁掉右下角水印后转的 WebP）。
 */

const title = 'CAPABILITY' // 左侧竖排标题（原组件同名 prop，默认 "Testimonials"）
const DURATION = 6000 // 自动切换间隔（原组件 duration 默认 6000）

// 每项配一张图。三张都由生成图裁掉右下角水印后转成 WebP（1024x920，62~176 KB）：
//   策略引擎 → 线框多面体核心 + 轨道节点    命名管道 → 等距玻璃管道 + 数据流
//   离线校验 → 线框钥匙 + 一圈虚线环
const items = [
  {
    quote: '负责信号判定、仓位管理与下单。所有决策逻辑集中在一处，便于回测与版本管理。',
    author: '主控',
    role: '风控内置',
    company: '策略引擎',
    image: '/images/capability/engine.webp',
  },
  {
    quote: '通过命名管道直驱终端，跳过轮询与文件落地，把指令在毫秒级送达每一台机器。',
    author: '跟随端',
    role: '多端同步',
    company: '命名管道',
    image: '/images/capability/pipeline.webp',
  },
  {
    quote: '离线激活码，绑定账号、经纪商与有效期。一次编译分发所有客户，按需发码即可。',
    author: '授权',
    role: '按客户发码',
    company: '离线校验',
    image: '/images/capability/license.webp',
  },
]

const active = ref(0)
const cur = computed(() => items[active.value])
const index = computed(() => String(active.value + 1).padStart(2, '0'))
const progress = computed(() => `${((active.value + 1) / items.length) * 100}%`)
const ticker = computed(() => items.map((t) => t.author).join(' • ') + ' • ')

// 原组件是 quote.split(' ') —— 中文没有空格，改成按标点断句。
// 断出来的每段仍带标点，正好是"逐词"的粒度：一次翻一个短句，不是翻一个字。
const words = computed(() => cur.value.quote.split(/(?<=[，。、；：])/).filter(Boolean))

// ---------------------------- 磁性视差 ----------------------------
// 原组件：useSpring(mouseX, { damping: 25, stiffness: 200 })，
// 再把 [-200, 200] 映射到 [-20, 20]（横向）/ [-10, 10]（纵向），超出夹紧。
const K = 200
const C = 25
const stageRef = ref(null)

const axis = { x: { cur: 0, vel: 0, tgt: 0 }, y: { cur: 0, vel: 0, tgt: 0 } }
let raf = 0
let last = 0

const clamp = (v, a, b) => (v < a ? a : v > b ? b : v)

function step(a, dt) {
  // 半隐式欧拉：acc = (-k·x - c·v) / m
  const acc = (-K * (a.cur - a.tgt) - C * a.vel) / 1
  a.vel += acc * dt
  a.cur += a.vel * dt
}

function frame(now) {
  raf = 0
  const dt = Math.min((now - last) / 1000, 0.05) || 0.016
  last = now
  step(axis.x, dt)
  step(axis.y, dt)

  const el = stageRef.value
  if (el) {
    // 原映射：sprung 值 / 200 * 20（纵向 * 10），夹紧到 ±20 / ±10
    el.style.setProperty('--num-x', `${clamp((axis.x.cur / 200) * 20, -20, 20).toFixed(2)}px`)
    el.style.setProperty('--num-y', `${clamp((axis.y.cur / 200) * 10, -10, 10).toFixed(2)}px`)
    // 图片走同一根鼠标量，但更轻、方向相反：一动鼠标，超大序号与图片各朝一边，纵深就出来了
    el.style.setProperty('--cap-mx', `${clamp((axis.x.cur / 200) * -9, -9, 9).toFixed(2)}px`)
    el.style.setProperty('--cap-my', `${clamp((axis.y.cur / 200) * -7, -7, 7).toFixed(2)}px`)
  }

  // 还没停就继续跑；静止后自动收手，不空转
  const moving =
    Math.abs(axis.x.cur - axis.x.tgt) > 0.2 ||
    Math.abs(axis.x.vel) > 0.2 ||
    Math.abs(axis.y.cur - axis.y.tgt) > 0.2 ||
    Math.abs(axis.y.vel) > 0.2
  if (moving) raf = requestAnimationFrame(frame)
}

function kick() {
  if (raf || reduce.value) return
  last = performance.now()
  raf = requestAnimationFrame(frame)
}

function onMove(e) {
  const el = stageRef.value
  if (!el) return
  const r = el.getBoundingClientRect()
  if (!r.width || !r.height) return
  // 原组件以容器中心为原点，存的是像素偏移（不是归一化值）
  axis.x.tgt = e.clientX - (r.left + r.width / 2)
  axis.y.tgt = e.clientY - (r.top + r.height / 2)
  kick()
}

function onLeave() {
  paused.value = false
  axis.x.tgt = 0
  axis.y.tgt = 0
  kick()
}

// ---------------------------- 滚动视差 + 图片入场 ----------------------------
// 两列反向位移：文字慢、图片快（数值是"整段穿过视口"的总行程，两列相差 42px）。
// 图片那句"由透明缩小逐渐放大"由同一支进度驱动：进入阶段 opacity 0 → 1、scale 0.88 → 1，
// 走完之后保持不变，只留视差。
//
// 变量写在 .dt 上、由子元素继承，直接 setProperty 不走响应式 —— 和首屏 veil 同一套做法，
// 否则滚动里每帧都要白跑一遍组件渲染。
const TEXT_TRAVEL = 16 // 文字列行程(px)，取负方向
const MEDIA_TRAVEL = 26 // 图片列行程(px)
const ENTER_SPAN = 0.6 // 走完进入段的多大比例就算入场完成（0.6 → 走 60% 时已完全显现）

let sraf = 0

function applyScroll() {
  sraf = 0
  const el = stageRef.value
  if (!el || reduce.value) return
  const r = el.getBoundingClientRect()
  const vh = window.innerHeight || 1
  // p: -1 = 整段还在视口下方，0 = 与视口居中，1 = 整段已越过视口
  const raw = (vh / 2 - (r.top + r.height / 2)) / ((vh + r.height) / 2 || 1)
  const p = raw < -1 ? -1 : raw > 1 ? 1 : raw
  const enter = Math.max(0, Math.min(1, (p + 1) / ENTER_SPAN))
  el.style.setProperty('--cap-text-y', `${(-TEXT_TRAVEL * p).toFixed(2)}px`)
  el.style.setProperty('--cap-media-y', `${(MEDIA_TRAVEL * p).toFixed(2)}px`)
  el.style.setProperty('--cap-media-o', enter.toFixed(3))
  el.style.setProperty('--cap-media-s', (0.88 + 0.12 * enter).toFixed(4))
}

function onScroll() {
  if (sraf) return
  sraf = requestAnimationFrame(applyScroll)
}

// ---------------------------- 自动轮播 ----------------------------
// 原组件只有 setInterval(goNext, duration)。这里多两条暂停条件：
//   悬停/聚焦 —— 鼠标在块上往往正是在看，不该被切走；
//   滚出视口 —— 否则它在下面几个区块里偷偷切，用户滚回来会莫名换了内容。
const paused = ref(false)
const live = ref(false)
const reduce = ref(false)
let timer = 0
let fallback = 0
let io = null

function schedule() {
  clearTimeout(timer)
  timer = window.setTimeout(() => {
    if (live.value && !paused.value) active.value = (active.value + 1) % items.length
    schedule()
  }, DURATION)
}

function go(step) {
  active.value = (active.value + step + items.length) % items.length
  schedule()
}

onMounted(() => {
  reduce.value = window.matchMedia('(prefers-reduced-motion: reduce)').matches

  if (typeof IntersectionObserver === 'undefined') {
    live.value = true
  } else {
    io = new IntersectionObserver(
      (entries) => {
        const hit = entries[entries.length - 1].isIntersecting
        if (hit) live.value = true
        paused.value = !hit
      },
      { threshold: 0.2 },
    )
    if (stageRef.value) io.observe(stageRef.value)
  }

  // 三张图先取回来：不预热的话，第一次自动换图会先空一帧再蹦出来
  items.forEach((it) => {
    const im = new Image()
    im.src = it.image
  })

  // 视差进度：先算一次（页面可能带着滚动偏移刷新进来），再挂监听。
  // 视口高度变了进度也得重算，所以 resize 一并挂上。
  applyScroll()
  window.addEventListener('scroll', onScroll, { passive: true })
  window.addEventListener('resize', onScroll, { passive: true })

  schedule()
})

onBeforeUnmount(() => {
  clearTimeout(timer)
  clearTimeout(fallback)
  if (raf) cancelAnimationFrame(raf)
  if (sraf) cancelAnimationFrame(sraf)
  if (io) io.disconnect()
  window.removeEventListener('scroll', onScroll)
  window.removeEventListener('resize', onScroll)
})
</script>

<template>
  <section id="capability" class="section cap">
    <div class="container">
      <div class="cap__head" v-reveal="{ selector: '.rv', stagger: 0.1 }">
        <p class="eyebrow rv">能力</p>
        <h2 class="title rv">三个部分，一条链路</h2>
      </div>

      <div
        ref="stageRef"
        class="dt"
        @mousemove="onMove"
        @pointerenter="paused = true"
        @pointerleave="onLeave"
        @focusin="paused = true"
        @focusout="paused = false"
      >
                <!-- 超大序号：6% 透明度，磁性视差作用在它身上。
             进出场比原版快一档：mode="out-in" 是先出场再入场，首尾相加才是重新出现的
             总时长 —— 原版两段都 0.6s，合计 1.2s，看着就是"半天才出来"。 -->
        <div class="dt__num" aria-hidden="true">
          <Transition name="dt-num" mode="out-in">
            <span :key="active" class="dt__num-in">{{ index }}</span>
          </Transition>
        </div>

        <div class="dt__row">
          <!-- 左列：竖排标题 + 竖向进度条 -->
          <div class="dt__side">
            <span class="dt__side-title">{{ title }}</span>
            <div class="dt__bar">
              <div class="dt__bar-fill" :style="{ height: progress }" />
            </div>
          </div>

          <!-- 主内容 -->
          <div class="dt__main">
            <Transition name="dt-pill" mode="out-in">
              <div :key="active" class="dt__pillwrap">
                <span class="dt__pill">
                  <span class="dt__dot" />
                  {{ cur.company }}
                </span>
              </div>
            </Transition>

            <Transition name="dt-quote" mode="out-in">
              <blockquote :key="active" class="dt__quote">
                <span
                  v-for="(w, i) in words"
                  :key="`${active}-${i}`"
                  class="dt__word"
                  :style="{ '--i': i }"
                >{{ w }}</span>
              </blockquote>
            </Transition>

            <div class="dt__foot">
              <Transition name="dt-author" mode="out-in">
                <div :key="active" class="dt__author">
                  <span class="dt__author-line" />
                  <span class="dt__author-text">
                    <span class="dt__author-name">{{ cur.author }}</span>
                    <span class="dt__author-role">{{ cur.role }}</span>
                  </span>
                </div>
              </Transition>

              <div class="dt__nav">
                <button type="button" class="dt__arrow" aria-label="上一项" @click="go(-1)">
                  <svg viewBox="0 0 16 16" width="18" height="18" fill="none">
                    <path
                      d="M10 12L6 8L10 4"
                      stroke="currentColor"
                      stroke-width="1.5"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                  </svg>
                </button>
                <button type="button" class="dt__arrow" aria-label="下一项" @click="go(1)">
                  <svg viewBox="0 0 16 16" width="18" height="18" fill="none">
                    <path
                      d="M6 4L10 8L6 12"
                      stroke="currentColor"
                      stroke-width="1.5"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                  </svg>
                </button>
              </div>
            </div>
          </div>

          <!-- 右列：图片。两层变换相乘，各管一段：
               .dt__media-move 承接「滚动视差 + 入场放大」（脚本写 --cap-media-* 变量），
               img 自己只负责换图时的那次缩放，两者互不覆盖。 -->
          <div class="dt__media">
            <div class="dt__media-move">
              <Transition name="dt-media" mode="out-in">
                <img
                  :key="active"
                  class="dt__img"
                  :src="cur.image"
                  :alt="`${cur.company} 示意图`"
                  width="1024"
                  height="920"
                  decoding="async"
                />
              </Transition>
            </div>
          </div>
        </div>

        <!-- 底部跑马灯 -->
        <div class="dt__ticker" aria-hidden="true">
          <div class="dt__ticker-track">
            <span v-for="n in 2" :key="n">{{ ticker }}</span>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.cap__head {
  max-width: 640px;
}
.cap{
  padding-top: 0;
}
/* 舞台：原组件是 min-h-screen 居中，这里收成一个区块，留出足够高度放那个大数字 */
.dt {
  --num-x: 0px;
  --num-y: 0px;
  position: relative;
  margin-top: clamp(44px, 6vw, 76px);
  padding: clamp(40px, 5vw, 72px) 0 clamp(96px, 10vw, 132px);
  border-top: 1px solid var(--line);
  overflow: hidden;
}

/* ---------------------------- 超大序号 ---------------------------- */
.dt__num {
  position: absolute;
  top: 44%;
  left: -10px;
  z-index: 0;
  transform: translate3d(var(--num-x), calc(-50% + var(--num-y)), 0);
  pointer-events: none;
  user-select: none;
}

.dt__num-in {
  display: block;
  font-size: clamp(160px, 24vw, 380px);
  font-weight: 700;
  line-height: 1;
  letter-spacing: -0.06em;
  color: rgba(255, 255, 255, 0.06); /* 原组件 text-foreground/6 */
}

/* ---------------------------- 三列：细轨 / 文字 / 图片 ---------------------------- */
/* 列间距统一由 gap 给，所以 .dt__side 不再自己垫 padding-right、
   .dt__main 也不再自己垫 padding-left —— 否则两处叠加会空出一大块。 */
.dt__row {
  position: relative;
  z-index: 1;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) minmax(0, 0.86fr);
  gap: clamp(24px, 3.2vw, 52px);
  align-items: center;
  padding-left: clamp(20px, 3vw, 44px);
}

.dt__side {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  border-right: 1px solid var(--line);
}

.dt__side-title {
  writing-mode: vertical-rl;
  text-orientation: mixed;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 11px;
  letter-spacing: 0.34em;
  text-transform: uppercase;
  color: var(--muted);
}

.dt__bar {
  position: relative;
  width: 1px;
  height: 128px;
  margin-top: 32px;
  background: var(--line-strong);
}

.dt__bar-fill {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  transform-origin: top;
  background: var(--cyan);
  transition: height 0.5s var(--ease);
}

.dt__main {
  min-width: 0;
  /* 左右留白交给 .dt__row 的列间距，这里只留上下 */
  padding: clamp(8px, 1.4vw, 20px) 0;
  /* 滚动视差：与图片列反向位移（脚本写 --cap-text-y） */
  transform: translate3d(0, var(--cap-text-y, 0px), 0);
  will-change: transform;
}

/* ---------------------------- 右列：图片 ---------------------------- */
/* 两层变换相乘，各管一段，互不覆盖：
   .dt__media-move  滚动视差（--cap-media-y）+ 入场放大（--cap-media-s / -o）+ 鼠标视差
   img              只在换图时缩放一次（见下方 .dt-media-* 过渡） */
.dt__media {
  position: relative;
}

.dt__media-move {
  transform: translate3d(
      var(--cap-mx, 0px),
      calc(var(--cap-media-y, 0px) + var(--cap-my, 0px)),
      0
    )
    scale(var(--cap-media-s, 1));
  opacity: var(--cap-media-o, 1);
  will-change: transform, opacity;
}

.dt__img {
  display: block;
  width: 100%;
  height: auto;
  aspect-ratio: 1024 / 920;
  object-fit: cover;
  border: 1px solid var(--line-strong);
  border-radius: 20px;
  /* 图本身就是深底，这条底色只用于加载完成前的空档 */
  background: #06070d;
}

/* 换图：旧图淡出并微微放大，新图"由透明缩小逐渐放大"。
   （入场阶段的那次放大在 .dt__media-move 上，由滚动进度驱动） */
.dt-media-enter-active {
  transition: opacity 0.55s var(--ease), transform 0.8s var(--ease);
}
.dt-media-leave-active {
  transition: opacity 0.35s var(--ease), transform 0.5s var(--ease);
}
.dt-media-enter-from {
  opacity: 0;
  transform: scale(0.94);
}
.dt-media-leave-to {
  opacity: 0;
  transform: scale(1.03);
}

/* ---------------------------- 公司胶囊 ---------------------------- */
.dt__pillwrap {
  margin-bottom: clamp(22px, 3vw, 34px);
}

.dt__pill {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 5px 13px;
  border: 1px solid var(--line-strong);
  border-radius: 99px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 11.5px;
  letter-spacing: 0.12em;
  color: var(--text-dim);
}

.dt__dot {
  width: 6px;
  height: 6px;
  border-radius: 99px;
  background: var(--cyan);
}

/* ---------------------------- 大字引用 ---------------------------- */
.dt__quote {
  margin: 0 0 clamp(34px, 4vw, 54px);
  min-height: clamp(120px, 14vw, 176px);
  font-size: clamp(26px, 3.4vw, 46px);
  font-weight: 300;
  line-height: 1.2;
  letter-spacing: 0.005em;
  color: var(--text);
  /* 让逐词翻转有透视，不然 rotateX 只会压扁而看不出翻 */
  perspective: 620px;
}

.dt__word {
  display: inline-block;
}

/* 逐词入场：原组件 delay i*0.05、y 20、rotateX 90 */
.dt-quote-enter-active .dt__word {
  animation: dt-word 0.5s var(--ease) both;
  animation-delay: calc(var(--i, 0) * 0.05s);
}

@keyframes dt-word {
  from {
    opacity: 0;
    transform: translateY(20px) rotateX(90deg);
  }
  to {
    opacity: 1;
    transform: translateY(0) rotateX(0);
  }
}

/* ---------------------------- 作者行 + 导航 ---------------------------- */
.dt__foot {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 24px;
}

.dt__author {
  display: flex;
  align-items: center;
  gap: 16px;
}

.dt__author-line {
  display: block;
  width: 32px;
  height: 1px;
  background: var(--text);
  transform-origin: left;
}

.dt-author-enter-active .dt__author-line {
  animation: dt-line 0.6s var(--ease) 0.3s both;
}

@keyframes dt-line {
  from {
    transform: scaleX(0);
  }
  to {
    transform: scaleX(1);
  }
}

.dt__author-text {
  display: flex;
  flex-direction: column;
}

.dt__author-name {
  font-size: 16px;
  font-weight: 500;
  color: var(--text);
}

.dt__author-role {
  font-size: 13.5px;
  color: var(--muted);
}

.dt__nav {
  display: flex;
  align-items: center;
  gap: 16px;
}

.dt__arrow {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 48px;
  height: 48px;
  padding: 0;
  border: 1px solid var(--line-strong);
  border-radius: 99px;
  background: transparent;
  color: var(--text);
  cursor: pointer;
  overflow: hidden;
  transition: color 0.35s var(--ease), border-color 0.35s var(--ease), transform 0.2s var(--ease);
}

/* 原组件那个从侧向滑入的填充层：这里用 ::before 平移实现 */
.dt__arrow::before {
  content: '';
  position: absolute;
  inset: 0;
  background: var(--cyan);
  transform: scaleX(0);
  transition: transform 0.4s var(--ease);
}

.dt__arrow:hover::before {
  transform: scaleX(1);
}

.dt__arrow:hover {
  color: #06070d;
  border-color: var(--cyan);
}

.dt__arrow:active {
  transform: scale(0.94);
}

.dt__arrow svg {
  position: relative;
  z-index: 1;
}

/* ---------------------------- 底部跑马灯 ---------------------------- */
.dt__ticker {
  position: absolute;
  left: 0;
  right: 0;
  bottom: clamp(-30px, -2vw, -10px);
  overflow: hidden;
  opacity: 0.08;
  pointer-events: none;
}

.dt__ticker-track {
  display: flex;
  white-space: nowrap;
  font-size: clamp(34px, 4.4vw, 62px);
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--text);
  animation: dt-ticker 20s linear infinite;
}

.dt__ticker-track span {
  padding-right: 0.6em;
}

@keyframes dt-ticker {
  from {
    transform: translateX(0);
  }
  to {
    transform: translateX(-50%);
  }
}

/* ---------------------------- 过渡（对应 AnimatePresence mode="wait"）--------------------------- */
/* out-in：先跑完 leave 才开始 enter，所以两段时长相加才是"重新出现"的总时间。
   原来各 0.6s = 1.2s；现在 0.34 + 0.2 = 0.54s。 */
.dt-num-enter-active {
  transition: opacity 0.34s var(--ease), transform 0.34s var(--ease), filter 0.34s var(--ease);
}
.dt-num-leave-active {
  transition: opacity 0.2s var(--ease), transform 0.2s var(--ease), filter 0.2s var(--ease);
}
.dt-num-enter-from {
  opacity: 0;
  transform: scale(0.8);
  filter: blur(10px);
}
.dt-num-leave-to {
  opacity: 0;
  transform: scale(1.1);
  filter: blur(10px);
}

.dt-pill-enter-active,
.dt-pill-leave-active {
  transition: opacity 0.4s var(--ease), transform 0.4s var(--ease);
}
.dt-pill-enter-from {
  opacity: 0;
  transform: translateX(-20px);
}
.dt-pill-leave-to {
  opacity: 0;
  transform: translateX(20px);
}

.dt-quote-leave-active {
  transition: opacity 0.2s var(--ease);
}
.dt-quote-leave-to {
  opacity: 0;
}

.dt-author-enter-active {
  transition: opacity 0.4s var(--ease) 0.2s, transform 0.4s var(--ease) 0.2s;
}
.dt-author-enter-from {
  opacity: 0;
  transform: translateY(20px);
}
.dt-author-leave-active {
  transition: opacity 0.3s var(--ease);
}
.dt-author-leave-to {
  opacity: 0;
  transform: translateY(-20px);
}

/* ---------------------------- 响应式与降级 ---------------------------- */
@media (max-width: 860px) {
  .dt__side {
    display: none;
  }
  /* 收成单列：文字在上、图片在下 */
  .dt__row {
    grid-template-columns: minmax(0, 1fr);
    gap: clamp(24px, 5vw, 40px);
    padding-left: 0;
  }
  .dt__num {
    left: -6px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .dt__num {
    transform: translateY(-50%);
  }
  .dt-quote-enter-active .dt__word,
  .dt-author-enter-active .dt__author-line {
    animation: none;
  }
  .dt__word {
    opacity: 1;
  }
  .dt__ticker-track {
    animation: none;
  }
  /* 视差与入场放大一并关掉（脚本此时也不再写变量，这里是兜住内联残留值） */
  .dt__main,
  .dt__media-move {
    transform: none;
  }
  .dt-media-enter-active,
  .dt-media-leave-active {
    transition: none;
  }
}
</style>
