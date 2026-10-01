<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * 页脚。
 *
 * 结构自上而下：
 *   1. 一条突出的 CTA（获取激活码）
 *   2. 信息条：品牌 / 版权 / 导航 / 回到顶部
 *   3. 免责声明（交易类站点必须有，但视觉上要弱化）
 *   4. 压在品牌色上的超大汉字字标，作为整页的收尾
 *
 * 字标用的是思源黑体 Heavy 的**汉字子集**（只含这四个字，1.3 KB），
 * 见 main.css 里的 @font-face。系统自带的雅黑 Bold 撑不起这个尺度的质感。
 *
 * 字号不是写死的：挂载后实测一行自然宽度，反推出刚好铺满容器的字号。
 * 换字体、换文案、换视口宽度都不用改数字。汉字每个字的字宽就是 1em，
 * 四个字铺满一行后，行高 1em 正好让色块高度等于字高 —— 所以字是完整显示的，
 * 不做裁切（裁切汉字会切到笔画，看着像坏了）。
 *
 * 交互：
 *   - 光标靠近时，最近的字被抬起、并轻微向外推开，按距离平方衰减；
 *   - 鼠标横向拖拽时整行跟着走，越靠后的字越"跟不上"，松手弹性回中，
 *     拖拽速度转成一点轻微倾斜。
 *   抬起幅度刻意小于汉字自身的内边距，这样字不会被色块边缘切掉。
 */

const year = new Date().getFullYear()

// 字标文案。汉字每个字的字宽 = 1em，铺满逻辑是自适应的。
// ⚠️ 改这里的同时要重新取字体子集（见 main.css 的说明）。
const MARK = '南门拈星'
const letters = [...MARK]

const nav = [
  { label: '理念', href: '#about' },
  { label: '能力', href: '#capability' },
  { label: '联系', href: '#contact' },
]

// ---------------------------- 交互参数 ----------------------------

const LIFT = 16 // 光标正下方字的最大抬升（px），必须小于汉字自身的内边距
const PUSH = 11 // 最大横向推开距离（px）
const REACH = 0.72 // 影响半径，单位是"单个字的宽度"
const DRAG_MAX = 170 // 拖拽最大位移（px）
const LAG = 0.14 // 每个字相对前一个的滞后比例
const MAX_FONT = 460 // 字号上限，避免超宽屏上色块高得离谱

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

  // 乘 1.004 让两端微微出血，避免出现一条发丝缝
  const size = Math.min((BASE * mark.clientWidth * 1.004) / natural, MAX_FONT)
  row.style.fontSize = size.toFixed(2) + 'px'
}

/** 缓存字标左边界、每个字的中心与宽度，帧内不再读布局 */
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
  // 拖拽期间不要清光标，否则抬起效果会突然断掉
  if (s.down) return
  s.active = false
  s.clientX = -1e4
}

// ---------------------------- 逐帧 ----------------------------

function frame() {
  raf = requestAnimationFrame(frame)

  // 拖拽位移：按下时跟手（快），松手回中（慢）
  s.drag += (s.target - s.drag) * (s.down ? 0.24 : 0.12)

  const v = s.drag - s.prevDrag
  s.prevDrag = s.drag
  s.skew += (v * 0.5 - s.skew) * 0.2

  const els = letterEls.value

  for (let i = 0; i < els.length; i++) {
    const el = els[i]
    if (!el) continue

    const lag = 1 - i * LAG

    // 光标影响：按到字中心的距离衰减，平方衰减让过渡更"软"
    let f = 0
    let dir = 1
    if (s.active) {
      const cx = centers[i] ?? 0
      const w = widths[i] ?? 1
      const delta = s.clientX - markLeft - s.drag * lag - cx
      const d = Math.abs(delta) / (REACH * w)
      if (d < 1) {
        f = (1 - d) * (1 - d)
        // 往光标的外侧推，负号处理光标正好落在字心的情况
        dir = delta >= 0 ? -1 : 1
      }
    }

    const tx = s.drag * lag + dir * f * PUSH
    const ty = -f * LIFT
    const skew = s.skew * lag

    el.style.transform =
      `translate3d(${tx.toFixed(2)}px, ${ty.toFixed(2)}px, 0) ` + `skewX(${skew.toFixed(2)}deg)`
  }
}

// ---------------------------- 生命周期 ----------------------------

function onScroll() {
  measure()
}

onMounted(() => {
  relayout()

  // 字体换上来之后字宽会变，必须重测一次
  if (document.fonts?.ready) document.fonts.ready.then(relayout)

  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches
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
    <!-- 收尾 CTA -->
    <div class="container ftr__cta">
      <a class="ftr__cta-link" href="#contact">
        获取授权激活码
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path
            d="M5 12h13M12.5 5.5 19 12l-6.5 6.5"
            fill="none"
            stroke="currentColor"
            stroke-width="1.6"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      </a>
    </div>

    <!-- 信息条 -->
    <div class="container ftr__bar">
      <div class="ftr__left">
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
      激活码按账号、经纪商与有效期签发。
    </p>

    <!-- 字标 -->
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

/* ---------------------------- CTA ---------------------------- */

.ftr__cta {
  display: flex;
  justify-content: flex-end;
  padding-top: 46px;
  padding-bottom: 30px;
  border-top: 1px solid var(--line);
}

.ftr__cta-link {
  display: inline-flex;
  align-items: center;
  gap: 9px;
  font-size: 15px;
  letter-spacing: 0.04em;
  color: var(--text-dim);
  transition: color 0.4s var(--ease);
}

.ftr__cta-link svg {
  width: 16px;
  height: 16px;
  transition: transform 0.45s var(--ease);
}

.ftr__cta-link:hover {
  color: var(--cyan);
}

.ftr__cta-link:hover svg {
  transform: translateX(5px);
}

/* ---------------------------- 信息条 ---------------------------- */

.ftr__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 18px 24px;
  padding-top: 24px;
  padding-bottom: 24px;
  border-top: 1px solid var(--line);
}

.ftr__left {
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
  padding-bottom: 46px;
  max-width: 78ch;
  font-size: 12.5px;
  line-height: 1.95;
  color: var(--muted);
}

/* ---------------------------- 超大汉字字标 ----------------------------
   字号由 JS 实测后写入，这里只定裁切与交互，所以字体、文案、
   视口宽度怎么变都不用改样式。
   行高 1 是有意的：汉字字宽 = 1em，四个字铺满一行时行高 1em 正好
   让色块高度等于字高，字完整显示、不被裁切。 */

.ftr__mark {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  background: var(--cyan);
  cursor: grab;
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
  font-size: 25vw;
  line-height: 1;
}

.ftr__letter {
  display: block;
  font-family: 'NMNX Display', 'PingFang SC', 'Microsoft YaHei', system-ui, sans-serif;
  font-weight: 900;
  color: var(--bg);
  transform-origin: 50% 100%;
  will-change: transform;
}

/* 字标对读屏是一串被拆散的字，用一份隐藏的完整文本代替 */
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
