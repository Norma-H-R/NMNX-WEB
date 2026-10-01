<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * 页脚。
 *
 * 上半是信息条：品牌 + 版权 + 导航 + 回到顶部，下面跟一行免责声明。
 * 下半是压在品牌色上的超大宇标（收尾那种"一块颜色压一个大字"的做法）：
 *
 *   1. 字标横向铺满、上下出血。字号不是写死的，而是挂载后实测一行自然宽度、
 *      换算成刚好铺满容器 —— 这样换字体、换字标内容、换视口宽度都不用改数字。
 *
 *   2. 光标靠近时，最近的字母被"顶起来"并竖向拉长，按距离平方衰减。
 *      只做竖向变化是有意的：竖向不会让字母互相挤压，横向缩放会让相邻字母撞在一起。
 *
 *   3. 鼠标横向拖拽时整行跟着走，越靠后的字母越"跟不上"（逐级滞后），
 *      松手用弹簧回中；拖拽速度还会转成一点轻微倾斜，带出惯性感。
 *
 * 性能取舍：逐帧直接写 DOM 的 style.transform，不走 Vue 响应式 ——
 * 4 个字母每帧新建响应式对象没必要。位置与宽度在挂载/字体加载/resize 时缓存，
 * 帧内不读 getBoundingClientRect，避免反复触发重排。
 */

const year = new Date().getFullYear()

// 字标内容。想换成中文（南门拈星）改这里即可，铺满逻辑是自适应的。
const MARK = 'NMNX'
const letters = [...MARK]

const nav = [
  { label: '理念', href: '#about' },
  { label: '能力', href: '#capability' },
  { label: '联系', href: '#contact' },
]

// ---------------------------- 交互参数 ----------------------------

const LIFT = 34 // 光标正下方字母的最大抬升（px）
const STRETCH = 0.36 // 最大竖向拉伸比例
const REACH = 0.62 // 影响半径，单位是"单个字母宽度"
const DRAG_MAX = 170 // 拖拽最大位移（px）
const LAG = 0.14 // 每个字母相对前一个的滞后比例

const markRef = ref(null)
const rowRef = ref(null)
const letterEls = ref([])

function bindLetter(i) {
  return (el) => {
    letterEls.value[i] = el
  }
}

let raf = 0
let markLeft = 0
let centers = []
let widths = []

// 指针与弹簧状态
const s = {
  clientX: -1e4,
  down: false,
  startX: 0,
  drag: 0,
  target: 0,
  prevDrag: 0,
  skew: 0,
  active: false,
}

// ---------------------------- 布局 ----------------------------

/** 测量一行自然宽度，反推出刚好铺满容器的字号 */
function fit() {
  const row = rowRef.value
  const mark = markRef.value
  if (!row || !mark) return

  const BASE = 100
  row.style.fontSize = `${BASE}px`
  const natural = row.getBoundingClientRect().width
  if (!natural) return

  // 乘 1.005 让两端微微出血，避免出现一条发丝缝
  row.style.fontSize = ((BASE * mark.clientWidth * 1.005) / natural).toFixed(2) + 'px'
}

/** 缓存字标左边界、每个字母的中心与宽度，帧内不再读布局 */
function measure() {
  const row = rowRef.value
  if (!row) return
  markLeft = row.getBoundingClientRect().left

  centers = []
  widths = []
  letterEls.value.forEach((el, i) => {
    if (!el) return
    centers[i] = el.offsetLeft + el.offsetWidth / 2
    widths[i] = el.offsetWidth || 1
  })
}

function relayout() {
  fit()
  measure()
}

function toTop() {
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

// ---------------------------- 指针 ----------------------------

function onPointerDown(e) {
  if (e.pointerType !== 'mouse') return
  s.down = true
  s.startX = e.clientX - s.drag
  markRef.value?.setPointerCapture?.(e.pointerId)
}

function onPointerMove(e) {
  s.clientX = e.clientX
  s.active = true
  if (s.down) {
    const d = e.clientX - s.startX
    s.target = Math.max(-DRAG_MAX, Math.min(DRAG_MAX, d))
  }
}

function onPointerUp() {
  s.down = false
  s.target = 0
}

function onPointerLeave() {
  // 拖拽期间不要清光标，否则抬升效果会突然断掉
  if (s.down) return
  s.active = false
  s.clientX = -1e4
}

// ---------------------------- 逐帧 ----------------------------

function frame() {
  raf = requestAnimationFrame(frame)

  // 拖拽位移：按下时跟手（快），松手回中（慢）
  s.drag += (s.target - s.drag) * (s.down ? 0.24 : 0.12)

  // 位移差转倾斜，再平滑一次，避免手抖直接反映到角度上
  const v = s.drag - s.prevDrag
  s.prevDrag = s.drag
  s.skew += (v * 0.55 - s.skew) * 0.2

  const els = letterEls.value

  for (let i = 0; i < els.length; i++) {
    const el = els[i]
    if (!el) continue

    const lag = 1 - i * LAG

    // 光标抬升：按到字母中心的距离衰减，平方衰减让过渡更"软"
    let f = 0
    if (s.active) {
      const cx = centers[i] ?? 0
      const w = widths[i] ?? 1
      const d = Math.abs(s.clientX - markLeft - s.drag * lag - cx) / (REACH * w)
      if (d < 1) f = (1 - d) * (1 - d)
    }

    const tx = s.drag * lag
    const ty = -f * LIFT
    const sy = 1 + f * STRETCH
    const skew = s.skew * lag

    el.style.transform =
      `translate3d(${tx.toFixed(2)}px, ${ty.toFixed(2)}px, 0) ` +
      `scaleY(${sy.toFixed(3)}) skewX(${skew.toFixed(2)}deg)`
  }
}

// ---------------------------- 生命周期 ----------------------------

let reduced = false

function onScroll() {
  // 只在横向位置可能变化时重测；纵向滚动不影响 markLeft
  measure()
}

onMounted(() => {
  relayout()

  // 字体换上来之后字母宽度会变，必须重测一次
  if (document.fonts?.ready) document.fonts.ready.then(relayout)

  reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  if (reduced) return

  raf = requestAnimationFrame(frame)
  window.addEventListener('resize', relayout)
  window.addEventListener('scroll', onScroll, { passive: true })
})

onBeforeUnmount(() => {
  cancelAnimationFrame(raf)
  window.removeEventListener('resize', relayout)
  window.removeEventListener('scroll', onScroll)
})
</script>

<template>
  <footer class="ftr">
    <div class="container ftr__bar">
      <div class="ftr__meta">
        <span class="ftr__brand">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path
              d="M12 1.6c.6 4.9 1.7 6 6.6 6.6-4.9.6-6 1.7-6.6 6.6-.6-4.9-1.7-6-6.6-6.6 4.9-.6 6-1.7 6.6-6.6Z"
              fill="currentColor"
            />
          </svg>
          南门拈星
        </span>
        <span class="ftr__copy">© {{ year }} 南门拈星</span>
      </div>

      <nav class="ftr__nav">
        <a v-for="l in nav" :key="l.href" :href="l.href">{{ l.label }}</a>
        <button type="button" class="ftr__top" @click="toTop">
          回到顶部
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path
              d="M12 18V6M5.5 12.5 12 6l6.5 6.5"
              fill="none"
              stroke="currentColor"
              stroke-width="1.6"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </button>
      </nav>
    </div>

    <p class="container ftr__note">
      本站内容仅为技术介绍，不构成任何投资建议。交易有风险，过往表现不代表未来收益。
    </p>

    <!-- 字标：铺满一行、上下出血。光标靠近会抬起，横向拖拽会跟着走 -->
    <div
      ref="markRef"
      class="ftr__mark"
      @pointerdown="onPointerDown"
      @pointermove="onPointerMove"
      @pointerup="onPointerUp"
      @pointercancel="onPointerUp"
      @pointerleave="onPointerLeave"
    >
      <div ref="rowRef" class="ftr__mark-row">
        <span
          v-for="(c, i) in letters"
          :key="i"
          :ref="bindLetter(i)"
          class="ftr__letter"
          aria-hidden="true"
        >{{ c }}</span>
      </div>
      <span class="ftr__sr">{{ MARK }}</span>
    </div>
  </footer>
</template>

<style scoped>
.ftr {
  position: relative;
  z-index: 1;
  margin-top: 24px;
}

/* ---------------------------- 信息条 ---------------------------- */

.ftr__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 20px 24px;
  padding-top: 34px;
  padding-bottom: 30px;
  border-top: 1px solid var(--line);
}

.ftr__meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px 22px;
}

.ftr__brand {
  display: inline-flex;
  align-items: center;
  gap: 9px;
  font-size: 14px;
  letter-spacing: 0.16em;
  color: var(--text-dim);
}

.ftr__brand svg {
  width: 16px;
  height: 16px;
  color: var(--cyan);
}

.ftr__copy {
  font-size: 12.5px;
  letter-spacing: 0.08em;
  color: #5a6178;
}

.ftr__nav {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px 26px;
  font-size: 13px;
}

.ftr__nav a {
  color: var(--text-dim);
  transition: color 0.35s var(--ease);
}

.ftr__nav a:hover {
  color: var(--text);
}

.ftr__top {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 0;
  border: 0;
  background: none;
  font: inherit;
  font-size: 13px;
  color: var(--muted);
  cursor: pointer;
  transition: color 0.35s var(--ease);
}

.ftr__top:hover {
  color: var(--cyan);
}

.ftr__top svg {
  width: 13px;
  height: 13px;
  transition: transform 0.4s var(--ease);
}

.ftr__top:hover svg {
  transform: translateY(-3px);
}

.ftr__note {
  margin: 0;
  padding-bottom: 44px;
  max-width: 76ch;
  font-size: 12.5px;
  line-height: 1.95;
  color: var(--muted);
}

/* ---------------------------- 超大宇标 ----------------------------
   压在品牌色上。字号由 JS 实测后写入，这里只定容器高度与裁切，
   所以字体、字标内容、视口宽度怎么变都不用改样式。 */

.ftr__mark {
  position: relative;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  height: clamp(112px, 20vw, 250px);
  overflow: hidden;
  background: var(--cyan);
  cursor: grab;
  /* 触屏上保留纵向滚动，只在横向才拦 */
  touch-action: pan-y;
  user-select: none;
}

.ftr__mark:active {
  cursor: grabbing;
}

.ftr__mark-row {
  position: relative;
  display: flex;
  /* JS 会覆盖这个值；留个兜底免得脚本没跑时字小得看不见 */
  font-size: 34vw;
  /* 行高压到比字形矮，配合容器裁切做出"上下出血"的效果 */
  line-height: 0.72;
  /* 轻微负字距，让字母咬合得更紧、更像一整块 */
  letter-spacing: -0.05em;
}

.ftr__letter {
  display: block;
  font-family: 'Inter Display', Inter, 'PingFang SC', 'Microsoft YaHei', system-ui, sans-serif;
  font-weight: 900;
  color: var(--bg);
  transform-origin: 50% 100%;
  will-change: transform;
}

/* 字距会加在最后一个字母后面，抵消掉才能让右边缘对齐容器 */
.ftr__letter:last-child {
  margin-right: -0.05em;
}

/* 字标对读屏是一串无意义的字母，用一份隐藏文本代替 */
.ftr__sr {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

@media (prefers-reduced-motion: reduce) {
  .ftr__letter {
    will-change: auto;
  }
}
</style>
