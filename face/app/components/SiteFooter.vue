<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * 页脚。
 *
 * 上半是信息条，下半是压在品牌色上的超大汉字字标。
 *
 * 字标沿用参考站的做法：**横向切片位移**（俗称 datamosh / glitch）。
 * 整行字被切成一条条横向细带，每条带左右错开不同的距离。
 *
 * 行为上有一个关键点（我一开始做错了，后来实测纠正）：
 *   **静止的时候字标是完全正常的，不动。**
 *   只有鼠标在它上面移动时才产生切片位移，位移幅度跟着鼠标移动的幅度走；
 *   鼠标停下之后，位移在一两秒内平滑衰减回零，字标重新变回静止。
 *
 *   （我之前做成了"一直在动"，是因为取样时截到了衰减过程中的帧，
 *     误判成了持续动画。后来把鼠标挪开、等足十秒稳定再取样，两次结果完全一致，
 *     才确认静止时它确实是不动的。）
 *
 * 实现上没有引 three.js —— 参考站那份是 r176，几百 KB。这个效果本质只是
 * 「每一行像素整体横移」，用 Canvas 2D 把字预渲染到离屏画布、逐条 drawImage
 * 回去就够了：先把字标画一次到离屏 canvas，之后每帧按固定高度切片重绘，
 * 每条给不同的 x 偏移。带数是 H / BAND，1080p 下约 40 次 drawImage / 帧，很轻。
 *
 * 完全静止时只重绘一次就停手，不空转 —— 移动端和低端机上这点很值。
 */

const year = new Date().getFullYear()

// 字标文案。汉字字宽 = 1em，四个字恰好铺满一行。
// ⚠️ 改这里要同步重新取字体子集（见 main.css 的说明）。
const MARK = '南门拈星'
const letters = [...MARK]

const nav = [
  { label: '理念', href: '#about' },
  { label: '能力', href: '#capability' },
  { label: '联系', href: '#contact' },
]

// ---------------------------- 可调参数 ----------------------------

const BAND = 9 // 切片高度（CSS 像素），越小吃得越细碎
const BAND_FREQ = 0.3 // 切片方向的相关性：越小，相邻切片越接近、错位越"成块"
const AMP = 30 // 满强度时的基础位移幅度（像素）
const SPEED = 1.1 // 切片图案的演化速度
const MOUSE_AMP = 70 // 鼠标横向位置带来的额外位移幅度
const MOUSE_REACH = 150 // 鼠标影响的垂直半径（像素）

// 位移由「移动强度」驱动，强度会随时间衰减。
// 这两个值决定手感：DECAY 越小衰减越快，MOVE_SCALE 越小越容易打满。
const ENERGY_DECAY = 0.1 // 强度每秒衰减到的倍数（0.1 ≈ 两秒内回到静止）
const MOVE_SCALE = 240 // 指针累计移动多少像素，强度加满

const MARK_MAX_W = 1600 // 画布最大宽度，与参考站一致
const INK = '#06070d' // 字色 = --bg
const BRAND = '#6ee7ff' // 底色 = --cyan

// ---------------------------- 状态 ----------------------------

const markRef = ref(null)
const canvasRef = ref(null)

let ctx = null
let off = null // 离屏画布：整行字就渲染在这里
let octx = null

let w = 0
let h = 0
let dpr = 1
let raf = 0
let startedAt = 0
let lastFrame = 0

const pointer = { x: -1e4, y: -1e4, ox: -1e4, oy: -1e4, lx: -1e4, ly: -1e4, active: false }

let energy = 0 // 移动强度 0~1：移动时累加，停下后指数衰减
let settled = true // 是否已经画完静态帧（静止时不重复重绘）

// ---------------------------- 噪声 ----------------------------

function hash1(v, seed) {
  const s = Math.sin(v * 127.1 + seed * 311.7) * 43758.5453123
  return s - Math.floor(s)
}

const smooth = (f) => f * f * (3 - 2 * f)

/**
 * 第 i 条切片在时刻 t 的位移，返回 -1 ~ 1。
 *
 * 沿**切片方向**和**时间方向**都做平滑插值，而不是每条切片直接取一个随机数 ——
 * 这样相邻几条会共享接近的偏移，错位才是"成块的"。
 * 这一点很关键：每条各自乱跳的话，字会被打成一堆辨认不出的碎片；
 * 参考稿之所以仍能读出 TRAE，就是因为它的错位是成块的。
 */
function bandNoise(i, t) {
  const ti = t * SPEED
  const t0 = Math.floor(ti)
  const tu = smooth(ti - t0)

  const fi = i * BAND_FREQ
  const f0 = Math.floor(fi)
  const fu = smooth(fi - f0)

  const v = (j, k) => hash1(j * 7.13 + k * 31.7, 3)

  const a = v(f0, t0) * (1 - fu) + v(f0 + 1, t0) * fu
  const b = v(f0, t0 + 1) * (1 - fu) + v(f0 + 1, t0 + 1) * fu
  return (a * (1 - tu) + b * tu) * 2 - 1
}

// ---------------------------- 绘制 ----------------------------

/** 把整行字标渲染到离屏画布；字号实测反推，保证刚好铺满一行 */
function buildText() {
  const el = canvasRef.value
  if (!el) return

  dpr = Math.min(window.devicePixelRatio || 1, 2)
  w = el.clientWidth
  h = el.clientHeight
  if (!w || !h) return

  el.width = Math.round(w * dpr)
  el.height = Math.round(h * dpr)
  ctx = el.getContext('2d')
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)

  // 改画布尺寸会把内容清空，所以后面必须重画一次
  settled = false

  off = document.createElement('canvas')
  off.width = Math.round(w * dpr)
  off.height = Math.round(h * dpr)
  octx = off.getContext('2d')
  octx.setTransform(dpr, 0, 0, dpr, 0, 0)
  octx.clearRect(0, 0, w, h)

  const family = "'NMNX Display', 'PingFang SC', 'Microsoft YaHei', system-ui, sans-serif"

  // 先量 100px 下这一行的宽度，再算出铺满画布需要的字号
  octx.font = `900 100px ${family}`
  const base = octx.measureText(MARK).width
  if (!base) return
  const size = ((w * 1.004) / base) * 100

  octx.font = `900 ${size}px ${family}`
  octx.textAlign = 'center'
  octx.textBaseline = 'middle'
  octx.fillStyle = INK
  octx.fillText(MARK, w / 2, h / 2)
}

/** 原样铺一次（无位移），用于静止状态 */
function drawStill() {
  ctx.fillStyle = BRAND
  ctx.fillRect(0, 0, w, h)
  ctx.drawImage(off, 0, 0, Math.round(w * dpr), Math.round(h * dpr), 0, 0, w, h)
}

function draw(t, dt) {
  // 强度指数衰减，约两秒内回到静止
  energy *= Math.pow(ENERGY_DECAY, dt)

  if (energy < 0.004) {
    energy = 0
    if (!settled) {
      drawStill()
      settled = true
    }
    return
  }
  settled = false

  // 鼠标位置缓动一点，避免手抖直接反映到位移方向
  pointer.ox += (pointer.x - pointer.ox) * 0.18
  pointer.oy += (pointer.y - pointer.oy) * 0.18

  // 光标横向位置决定整体往哪边推
  const bias = pointer.active ? (pointer.ox - w / 2) / (w / 2) : 0

  ctx.fillStyle = BRAND
  ctx.fillRect(0, 0, w, h)

  const e = Math.min(1, energy)
  const bands = Math.ceil(h / BAND)
  const sw = Math.round(w * dpr)

  for (let i = 0; i < bands; i++) {
    const y = i * BAND
    const bh = Math.min(BAND, h - y)
    if (bh <= 0) break

    let dx = bandNoise(i, t) * AMP * e

    // 光标附近的切片被推得更狠，越远衰减越快
    if (pointer.active) {
      const d = Math.abs(y + bh / 2 - pointer.oy) / MOUSE_REACH
      if (d < 1) {
        const k = (1 - d) * (1 - d)
        dx += bias * MOUSE_AMP * k * e
      }
    }

    ctx.drawImage(off, 0, Math.round(y * dpr), sw, Math.round(bh * dpr), dx, y, w, bh)
  }
}

function frame(now) {
  raf = requestAnimationFrame(frame)

  const t = (now - startedAt) * 0.001
  const dt = lastFrame ? Math.min(0.1, (now - lastFrame) * 0.001) : 0.016
  lastFrame = now

  draw(t, dt)
}

function onPointerMove(e) {
  const r = canvasRef.value.getBoundingClientRect()
  pointer.x = e.clientX - r.left
  pointer.y = e.clientY - r.top
  pointer.active = true

  // 按这一次移动的位移累加强度 —— 移动越快越猛，对应"跟着鼠标幅度走"
  if (pointer.lx > -1e3) {
    const d = Math.hypot(e.clientX - pointer.lx, e.clientY - pointer.ly)
    energy = Math.min(1, energy + d / MOVE_SCALE)
  }
  pointer.lx = e.clientX
  pointer.ly = e.clientY
}

function onPointerLeave() {
  pointer.active = false
  pointer.x = -1e4
  pointer.y = -1e4
  pointer.lx = -1e4
  pointer.ly = -1e4
}

function onVisibility() {
  if (document.hidden) {
    cancelAnimationFrame(raf)
    raf = 0
  } else if (!raf) {
    lastFrame = 0
    raf = requestAnimationFrame(frame)
  }
}

function rebuild() {
  buildText()
  drawStill()
  settled = true
}

let resizeTimer = 0
function onResize() {
  clearTimeout(resizeTimer)
  resizeTimer = setTimeout(rebuild, 160)
}

function toTop() {
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

onMounted(() => {
  buildText()
  drawStill()
  settled = true

  // 字体换上来之后字宽会变，必须重测重画
  if (document.fonts?.ready) document.fonts.ready.then(rebuild)

  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches

  if (reduced) {
    // 关掉动效时字标就是一张静态图，鼠标也不参与。
    // 特意打一行日志，否则在页面上完全看不出是"被降级了"还是"写坏了"。
    console.info('[SiteFooter] 检测到 prefers-reduced-motion，字标保持静态。')
    return
  }

  startedAt = performance.now()
  raf = requestAnimationFrame(frame)

  window.addEventListener('resize', onResize)
  document.addEventListener('visibilitychange', onVisibility)
})

onBeforeUnmount(() => {
  clearTimeout(resizeTimer)
  cancelAnimationFrame(raf)
  window.removeEventListener('resize', onResize)
  document.removeEventListener('visibilitychange', onVisibility)
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

    <!-- 字标：鼠标在它上面移动时才产生横向切片位移 -->
    <div
      ref="markRef"
      class="ftr__mark"
      @pointermove="onPointerMove"
      @pointerleave="onPointerLeave"
    >
      <div class="ftr__mark-inner">
        <canvas ref="canvasRef" class="ftr__canvas" />
      </div>
      <span class="ftr__sr">{{ letters.join('') }}</span>
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
  padding-bottom: 44px;
  max-width: 78ch;
  font-size: 12.5px;
  line-height: 1.95;
  color: var(--muted);
}

/* ---------------------------- 字标 ----------------------------
   底色整幅铺满，画布限宽居中（与参考站一致：那边也是
   max-w-[1600px] 的画布 + 整幅品牌色）。
   高度取 1/4 屏宽：四个汉字各占 1em，铺满一行时字高正好是宽度的四分之一。 */

.ftr__mark {
  position: relative;
  overflow: hidden;
  background: var(--cyan);
  height: calc(min(100vw, 1600px) / 4);
}

.ftr__mark-inner {
  width: 100%;
  max-width: 1600px;
  height: 100%;
  margin: 0 auto;
  overflow: hidden;
}

.ftr__canvas {
  display: block;
  width: 100%;
  height: 100%;
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
</style>
