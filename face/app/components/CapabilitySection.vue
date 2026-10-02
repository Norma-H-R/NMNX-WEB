<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * 能力区块 —— 按 Inspira UI「Design Testimonials」那套特效改写。
 *
 * 原组件是 Vue + Tailwind + motion-v，四个要素：
 *   1. cinematic typography  大字排版，重心落在文字本身
 *   2. magnetic parallax     鼠标移动时各层按不同幅度轻微跟随，产生纵深
 *   3. word-by-word          文本按单位错开入场，不是整块淡入
 *   4. auto-cycling nav      自动轮播 + 手动导航，悬停暂停
 *
 * 本项目零依赖、手写 CSS，所以不引 Tailwind/motion-v，按同样的行为自己实现：
 *   - 视差：指针在舞台内的归一化位置写进 --px/--py，各层用不同系数，位移交给
 *     CSS transition（不需要弹簧库）；
 *   - 逐字：中文没有词边界，按"字"拆（和首屏标题同一套 --i 错开延迟的写法）；
 *   - 轮播：换一条时用 :key 让整块重挂，入场动画自然重播，不需要 AnimatePresence。
 *
 * 自动轮播在两个条件之一下暂停，原因各不相同：
 *   - 鼠标悬停 / 键盘聚焦：用户在读，不该被切走；
 *   - 滚出视口：它在下面几个区块里偷偷切，用户滚回来会莫名其妙换了内容。
 */
const items = [
  {
    no: '01',
    title: '主控',
    desc: '负责信号判定、仓位管理与下单。所有决策逻辑集中在一处，便于回测与版本管理。',
    tags: ['策略引擎', '风控内置'],
  },
  {
    no: '02',
    title: '跟随端',
    desc: '通过命名管道直驱终端，跳过轮询与文件落地，把指令在毫秒级送达每一台机器。',
    tags: ['命名管道', '多端同步'],
  },
  {
    no: '03',
    title: '授权',
    desc: '离线激活码，绑定账号、经纪商与有效期。一次编译分发所有客户，按需发码即可。',
    tags: ['离线校验', '按客户发码'],
  },
]

const CYCLE = 7000 // 自动轮播间隔（毫秒），够读完一段

const active = ref(0)
const cur = computed(() => items[active.value])
const total = String(items.length).padStart(2, '0')

// 逐字入场：标题和正文都拆成单字，各自带 --i，CSS 用它算错开延迟。
// 正文的序号接在标题后面，于是两段看起来是连续往下淌的。
const titleChars = computed(() => [...cur.value.title])
const descChars = computed(() => [...cur.value.desc])

// ---------------------------- 磁性视差 ----------------------------
const stageRef = ref(null)

function setShift(x, y) {
  const el = stageRef.value
  if (!el) return
  el.style.setProperty('--px', x.toFixed(3))
  el.style.setProperty('--py', y.toFixed(3))
}

function onMove(e) {
  const el = stageRef.value
  if (!el) return
  const r = el.getBoundingClientRect()
  if (!r.width || !r.height) return
  // 归一化到 -1 ~ 1，原点取舞台中心
  setShift(((e.clientX - r.left) / r.width) * 2 - 1, ((e.clientY - r.top) / r.height) * 2 - 1)
}

function onLeave() {
  paused.value = false
  setShift(0, 0) // 指针离开要把位移归零，否则各层会永久停在偏移位
}

// ---------------------------- 轮播 ----------------------------
const paused = ref(false)
const seen = ref(false) // 进过一次视口 —— 入场动画与轮播都以它为前提
const safe = ref(false) // 兜底放行：只让文字可见，不播动画
let timer = 0
let fallback = 0
let io = null

function schedule() {
  clearTimeout(timer)
  timer = window.setTimeout(() => {
    if (seen.value && !paused.value) active.value = (active.value + 1) % items.length
    schedule()
  }, CYCLE)
}

function go(step) {
  active.value = (active.value + step + items.length) % items.length
  schedule()
}

function pick(i) {
  active.value = i
  schedule()
}

onMounted(() => {
  // 没有 IntersectionObserver 就直接放行，别把文字锁在 opacity:0
  if (typeof IntersectionObserver === 'undefined') {
    seen.value = true
  } else {
    io = new IntersectionObserver(
      (entries) => {
        const hit = entries[entries.length - 1].isIntersecting
        if (hit) seen.value = true
        paused.value = !hit
      },
      { threshold: 0.25 },
    )
    if (stageRef.value) io.observe(stageRef.value)
  }
  // 兜底：万一观察器始终不触发（布局异常等），2.5s 后先把文字放出来，
  // 否则会一直停在 opacity:0。
  // 注意这里只置 safe（可见、不播动画），不置 seen —— 如果兜底直接播了入场动画，
  // 它在屏幕外就播完了，用户滚下来时只会看到静止的一帧，逐字入场等于白做。
  // 真的滚到时观察器再置 seen，动画从隐藏态正常播。
  fallback = window.setTimeout(() => (safe.value = true), 2500)
  schedule()
})

onBeforeUnmount(() => {
  clearTimeout(timer)
  clearTimeout(fallback)
  if (io) io.disconnect()
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
        class="cap__stage"
        :class="{ 'is-live': seen, 'is-safe': safe }"
        @pointermove="onMove"
        @pointerenter="paused = true"
        @pointerleave="onLeave"
        @focusin="paused = true"
        @focusout="paused = false"
      >
        <!-- :key 绑 active —— 换一条时整块重挂，逐字入场自然重播 -->
        <div :key="active" class="cap__slide">
          <p class="cap__idx">
            <span class="cap__no">{{ cur.no }}</span>
            <span class="cap__total">/ {{ total }}</span>
          </p>

          <h3 class="cap__big" :aria-label="cur.title">
            <span
              v-for="(c, i) in titleChars"
              :key="`t${i}`"
              class="cap__ch"
              :style="{ '--i': i }"
              aria-hidden="true"
            >{{ c }}</span>
          </h3>

          <p class="cap__quote">
            <span
              v-for="(c, i) in descChars"
              :key="`d${i}`"
              class="cap__ch"
              :style="{ '--i': i + titleChars.length + 3 }"
            >{{ c }}</span>
          </p>

          <ul class="cap__tags">
            <li v-for="t in cur.tags" :key="t">{{ t }}</li>
          </ul>
        </div>

        <div class="cap__nav">
          <button type="button" class="cap__arrow" aria-label="上一项" @click="go(-1)">←</button>

          <div class="cap__dots">
            <button
              v-for="(it, i) in items"
              :key="it.no"
              type="button"
              class="cap__dot"
              :class="{ 'is-on': i === active }"
              :aria-label="`第 ${i + 1} 项：${it.title}`"
              :aria-current="i === active"
              @click="pick(i)"
            />
          </div>

          <button type="button" class="cap__arrow" aria-label="下一项" @click="go(1)">→</button>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.cap__head {
  max-width: 640px;
}

/* 舞台：一条上边线 + 底部导航。视差的三个层级都挂在它下面的 --px/--py 上 */
.cap__stage {
  --px: 0;
  --py: 0;
  position: relative;
  margin-top: clamp(48px, 7vw, 84px);
  padding-top: clamp(28px, 3.4vw, 46px);
  border-top: 1px solid var(--line);
}

/* 换一条时舞台高度不要跳 */
.cap__slide {
  min-height: clamp(230px, 24vw, 286px);
}

/* 视差：外层整体跟随，内层反向一点，拉开纵深；位移量都很小，是"磁吸"不是"甩动" */
.cap__idx,
.cap__big,
.cap__quote,
.cap__tags {
  transition: transform 0.7s var(--ease);
}

.cap__idx {
  display: flex;
  align-items: baseline;
  gap: 8px;
  transform: translate3d(calc(var(--px) * 10px), calc(var(--py) * 6px), 0);
}

.cap__no {
  font-size: 12px;
  letter-spacing: 0.3em;
  color: var(--muted);
}

.cap__total {
  font-size: 12px;
  letter-spacing: 0.2em;
  color: var(--line-strong);
}

.cap__big {
  margin-top: clamp(18px, 2.4vw, 30px);
  font-size: clamp(38px, 6.4vw, 96px);
  font-weight: 500;
  line-height: 1.06;
  letter-spacing: 0.02em;
  color: #fff;
  transform: translate3d(calc(var(--px) * -7px), calc(var(--py) * -5px), 0);
}

.cap__quote {
  margin-top: clamp(16px, 2vw, 26px);
  max-width: 34ch;
  font-size: clamp(16px, 1.5vw, 22px);
  line-height: 1.9;
  color: var(--text-dim);
  transform: translate3d(calc(var(--px) * 5px), calc(var(--py) * 3px), 0);
}

/* 逐字入场。默认 opacity:0，进过视口（.is-live）才开始播；
   换一条时整块重挂，动画自然重播 —— 这就是"逐词动画"那一层的做法。 */
.cap__ch {
  display: inline-block;
  opacity: 0;
}

/* 兜底：观察器没触发时至少让文字可见（不播动画，免得在屏幕外白闪一下） */
.cap__stage.is-safe .cap__ch {
  opacity: 1;
}

/* 真的进过视口才播逐字入场。这条必须排在 .is-safe 之后 —— 两处同权重、后者胜出，
   于是即便兜底已经把文字放出来，用户滚到时入场动画照样从头播。 */
.cap__stage.is-live .cap__ch {
  animation: cap-in 0.72s var(--ease) both;
  animation-delay: calc(var(--i, 0) * 0.016s);
}

@keyframes cap-in {
  from {
    opacity: 0;
    transform: translateY(0.42em);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.cap__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: clamp(26px, 3vw, 38px) 0 0;
  padding: 0;
  list-style: none;
  transform: translate3d(calc(var(--px) * 3px), calc(var(--py) * 2px), 0);
}

.cap__tags li {
  padding: 5px 13px;
  border: 1px solid var(--line);
  border-radius: 99px;
  font-size: 12px;
  letter-spacing: 0.06em;
  color: var(--muted);
  transition: color 0.4s var(--ease), border-color 0.4s var(--ease);
}

.cap__stage:hover .cap__tags li {
  color: var(--text-dim);
  border-color: var(--line-strong);
}

/* ---------------------------- 底部导航 ---------------------------- */
.cap__nav {
  display: flex;
  align-items: center;
  gap: 20px;
  margin-top: clamp(30px, 3.6vw, 46px);
}

.cap__arrow {
  width: 42px;
  height: 42px;
  padding: 0;
  border: 1px solid var(--line-strong);
  border-radius: 99px;
  background: transparent;
  color: var(--text-dim);
  font: inherit;
  font-size: 14px;
  cursor: pointer;
  transition: color 0.35s var(--ease), border-color 0.35s var(--ease), transform 0.35s var(--ease);
}

.cap__arrow:hover {
  color: var(--text);
  border-color: var(--text-dim);
}

.cap__arrow:active {
  transform: scale(0.94);
}

.cap__dots {
  display: flex;
  align-items: center;
  gap: 10px;
}

.cap__dot {
  width: 26px;
  height: 2px;
  padding: 0;
  border: 0;
  border-radius: 2px;
  background: var(--line-strong);
  cursor: pointer;
  transition: width 0.5s var(--ease), background 0.5s var(--ease);
}

.cap__dot.is-on {
  width: 48px;
  background: var(--cyan);
}

@media (prefers-reduced-motion: reduce) {
  .cap__ch {
    opacity: 1;
  }
  .cap__stage.is-live .cap__ch {
    animation: none;
  }
  .cap__idx,
  .cap__big,
  .cap__quote,
  .cap__tags {
    transform: none;
    transition: none;
  }
}

@media (max-width: 940px) {
  .cap__slide {
    min-height: 0;
  }
}
</style>
