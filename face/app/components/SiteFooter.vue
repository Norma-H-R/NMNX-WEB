<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * 页脚。
 *
 * 上半是信息条，下半是压在品牌色上的超大汉字字标。
 *
 * 字标效果：**电子故障风（glitch）**，类似抖音那种红蓝错位的故障抖动。
 * 关键点：
 *   - **大部分时间是正常静止的字**，可读性优先。
 *   - 每隔 1.6~4.4 秒随机爆发一次，持续约 0.26~0.7 秒；爆发期间整幅字做
 *     红/紫两色 RGB 错位 + 上下微抖 + 几条横向切片被推开，结束后迅速回落。
 *   - 纯时间驱动，不依赖鼠标；完全自托管，不引任何动画库。
 *
 * 实现：Canvas 2D。把字预渲染到离屏画布，再用「红/紫两色副本左右错开 + 墨色主字」
 * 做色差分裂（source-in 染色）；爆发时叠加横向切片位移。带数 = H / BAND，很轻。
 * 爆发间隔内的静止帧只画一次就停手，不空转 —— 移动端和低端机上这点很值。
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
const AMP = 34 // 故障切片的最大横向错位（像素）
const SPLIT = 16 // RGB 分裂的最大横向错位（像素）
const SHAKE = 3 // 爆发时的整幅上下抖动（像素）
const SPEED = 1.1 // 切片图案的演化速度

// 色差分裂的两路颜色。背景是青色（#6ee7ff），所以用红 + 紫：
// 红色在青色上最跳，紫色是本站强调色，都比"青底 + 蓝字"这种同色系更出效果。
const RGB_RED = '#ff3355'
const RGB_VIOLET = '#8b5cf6'

// 爆发调度：多久爆发一次、持续多久
const BURST_MIN = 0.26
const BURST_MAX = 0.7
const GAP_MIN = 1.6
const GAP_MAX = 4.4

const INK = '#06070d' // 字色 = --bg
const BRAND = '#6ee7ff' // 底色 = --cyan

// ---------------------------- 状态 ----------------------------

const canvasRef = ref(null)

let ctx = null
let off = null // 离屏画布：墨色整行字
let offRed = null // 红色副本（RGB 分裂用）
let offViolet = null // 紫色副本（RGB 分裂用）
let octx = null

let w = 0
let h = 0
let dpr = 1
let raf = 0
let startedAt = 0

let settled = true // 是否已经画完静止帧（爆发间隔不重复重绘）

// 故障调度状态
let burstStart = -10
let burstEnd = -10
let nextAt = 1.2

// ---------------------------- 噪声 ----------------------------

function hash1(v, seed) {
  const s = Math.sin(v * 127.1 + seed * 311.7) * 43758.5453123
  return s - Math.floor(s)
}

const smooth = (f) => f * f * (3 - 2 * f)

// 一维值噪声 0~1，用于爆发期间的高频抖动
function vnoise(x, seed) {
  const i = Math.floor(x)
  const f = smooth(x - i)
  const a = hash1(i, seed)
  const b = hash1(i + 1, seed)
  return a * (1 - f) + b * f
}

/**
 * 第 i 条切片在时刻 t 的位移，返回 -1 ~ 1。
 * 沿切片方向和时间方向都做平滑插值，错位才"成块"，而不是一堆碎片。
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

/**
 * 故障强度 0~1：大部分时间为 0（正常字），偶尔爆发。
 * 到点了就随机排下一次爆发；爆发内用正弦包络 × 高频抖动，让它像"乱码"。
 */
function glitchEnv(t) {
  if (t >= nextAt) {
    burstStart = t
    burstEnd = t + BURST_MIN + Math.random() * (BURST_MAX - BURST_MIN)
    nextAt = burstEnd + GAP_MIN + Math.random() * (GAP_MAX - GAP_MIN)
  }
  if (t < burstStart || t > burstEnd) return 0
  const p = (t - burstStart) / (burstEnd - burstStart)
  const env = Math.sin(p * Math.PI)
  const jitter = 0.6 + 0.4 * vnoise(t * 17, 11)
  return env * jitter
}

// ---------------------------- 绘制 ----------------------------

// 把墨色字染成单色副本（只染字形，透明区域不动）
function tint(src, color) {
  const c = document.createElement('canvas')
  c.width = src.width
  c.height = src.height
  const g = c.getContext('2d')
  g.drawImage(src, 0, 0)
  g.globalCompositeOperation = 'source-in'
  g.fillStyle = color
  g.fillRect(0, 0, c.width, c.height)
  return c
}

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
  settled = false

  off = document.createElement('canvas')
  off.width = Math.round(w * dpr)
  off.height = Math.round(h * dpr)
  octx = off.getContext('2d')
  octx.setTransform(dpr, 0, 0, dpr, 0, 0)
  octx.clearRect(0, 0, w, h)

  const family = "'NMNX Display', 'PingFang SC', 'Microsoft YaHei', system-ui, sans-serif"

  octx.font = `900 100px ${family}`
  const base = octx.measureText(MARK).width
  if (!base) return
  const size = ((w * 1.004) / base) * 100

  octx.font = `900 ${size}px ${family}`
  octx.textAlign = 'center'
  octx.textBaseline = 'middle'
  octx.fillStyle = INK
  octx.fillText(MARK, w / 2, h / 2)

  offRed = tint(off, RGB_RED)
  offViolet = tint(off, RGB_VIOLET)
}

/** 原样铺一次（无位移），用于静止状态 */
function drawStill() {
  ctx.fillStyle = BRAND
  ctx.fillRect(0, 0, w, h)
  ctx.drawImage(off, 0, 0, Math.round(w * dpr), Math.round(h * dpr), 0, 0, w, h)
}

function draw(t) {
  const g = glitchEnv(t)

  // 爆发间隔：只画一次静止帧就停手，不空转
  if (g < 0.005) {
    if (!settled) {
      drawStill()
      settled = true
    }
    return
  }
  settled = false

  const shake = g * SHAKE
  const split = g * SPLIT
  const sw = Math.round(w * dpr)
  const sh = Math.round(h * dpr)

  ctx.fillStyle = BRAND
  ctx.fillRect(0, 0, w, h)

  // 色差分裂：红/紫两路左右错开，墨色主字压在中间
  ctx.globalAlpha = g * 0.9
  ctx.drawImage(offRed, 0, 0, sw, sh, -split, shake, w, h)
  ctx.drawImage(offViolet, 0, 0, sw, sh, split, shake, w, h)
  ctx.globalAlpha = 1

  ctx.drawImage(off, 0, 0, sw, sh, 0, shake, w, h)

  // 爆发期间叠加几条横向错位的故障切片
  const bands = Math.ceil(h / BAND)
  for (let i = 0; i < bands; i++) {
    const y = i * BAND
    const bh = Math.min(BAND, h - y)
    if (bh <= 0) break
    const dx = bandNoise(i, t) * AMP * g
    if (Math.abs(dx) < 0.6) continue
    ctx.drawImage(off, 0, Math.round(y * dpr), sw, Math.round(bh * dpr), dx, y + shake, w, bh)
  }
}

function frame(now) {
  raf = requestAnimationFrame(frame)
  const t = (now - startedAt) * 0.001
  draw(t)
}

function onVisibility() {
  if (document.hidden) {
    cancelAnimationFrame(raf)
    raf = 0
  } else if (!raf) {
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

  if (document.fonts?.ready) document.fonts.ready.then(rebuild)

  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  if (reduced) {
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

    <!-- 字标：电子故障风（红紫色差 + 间歇爆发 + 切片错位） -->
    <div class="ftr__mark">
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
