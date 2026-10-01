<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * 点阵云团背景。
 *
 * 参考 trae.ai：纯黑底上是一大片由小方块聚成的**云雾团**，占据视口上方约五成，
 * 有浓核、有空洞、有游离散点，浓密处泛青，整体缓慢变形。
 * 观感上很像把一张低分辨率位图放大 —— 其实就是「把一个平滑的密度场做阈值化」。
 *
 * 和第一版的根本区别：第一版用的是一维山脊函数（每列一个高度），
 * 出来是一条细波浪带；这里是二维噪声密度场，才会成"团"。
 *
 * 调参上最关键的一条：**大尺度分布要压过细节噪声**，否则密度全挤在均值附近，
 * 云就糊成一整片灰，既没有浓核也没有空洞。所以下面 mac 占 0.74 而 det 只占 0.26。
 *
 * 性能设计（重点，二维逐点采样每帧几万次会很慢）：
 *   - 密度场只在 resize 时算一次，存进 Float32Array；
 *   - 同时算两份场（不同噪声偏移），帧间交叉淡入淡出，云团就会缓慢变形，
 *     每帧的工作量只剩一次线性插值；
 *   - 逐点哈希用整数位运算（Math.imul），不碰 Math.sin，比三角函数快得多；
 *   - 每帧先比阈值再决定要不要画，实际绘制的点只有全部格子的一部分。
 */

// ---------------------------- 可调参数 ----------------------------

const SPACING = 6 // 格子间距（CSS 像素）
const DOT = 5 // 方块边长，比间距小一点，方块之间会留出暗缝，才有"点阵"感
const SIZE_JITTER = 1.3 // 方块尺寸的随机浮动，打散整齐感

const CLOUD_Y = 0.38 // 云团中心在视口高度上的位置
const CLOUD_SPREAD = 0.34 // 云团的垂直扩散半径（相对视口高度）
const FIELD_X = 150 // 细节噪声在 x 方向的尺度（越大，云块越宽）
const FIELD_Y = 62 // 细节噪声在 y 方向的尺度（比 x 小 → 云块横向拉长）

// 阈值和上限是照着**实测的密度分布**定的（把密度场跑出来统计分位数再挑），
// 不是拍脑袋。改上面的噪声尺度或权重之后，这两个数要重新对一遍。
const THRESHOLD = 0.55
const PEAK = 1.45 // 归一化上限：达到这个密度就完全不透明
const GAMMA = 0.8 // 亮度曲线，<1 让中间调提亮一点

const MORPH_PERIOD = 11 // 两份密度场交叉淡入淡出的周期（秒），越小变形越明显

// 鼠标交互：光标只让点**变色**（向青色靠拢），不产生位移（凸起）
const REACH = 220 // 变色影响半径（像素）
const HOVER = 0.9 // 光标附近的变青强度上限

// 环境变化（让云团"活"起来，而不是基本冻结）
const TWINKLE = 0.2 // 单个点闪烁的幅度（0 就完全不闪）
const TWINKLE_SPEED = 1.4 // 闪烁速度
const DRIFT_X = 22 // 整团云缓慢水平漂移的幅度（像素）
const DRIFT_Y = 10 // 整团云缓慢垂直漂移的幅度（像素）

// 颜色与 main.css 里的设计变量保持一致（数组便于做颜色插值）
const RGB_DOT = [226, 236, 245] // 接近 --text
const RGB_ACCENT = [110, 231, 255] // = --cyan
const ACCENT_AT = 0.8 // 归一化亮度高于此值才转强调色，于是青色只出现在最浓的核里

// ---------------------------- 状态 ----------------------------

const canvasRef = ref(null)

let ctx = null
let raf = 0
let w = 0
let h = 0
let cols = 0
let rows = 0
let fieldA = null
let fieldB = null
let jitter = null
let startedAt = 0

const pointer = {
  cx: -1e4,
  cy: -1e4,
  ncx: -1e4,
  ncy: -1e4,
  active: false,
}

// ---------------------------- 噪声 ----------------------------

// 整数哈希。用 Math.imul 做 32 位乘法，避免 JS 数值精度问题。
function hash2(ix, iy, seed) {
  let n = Math.imul(ix, 374761393) + Math.imul(iy, 668265263) + Math.imul(seed, 1442695041)
  n = Math.imul(n ^ (n >>> 13), 1274126177)
  n ^= n >>> 16
  return (n >>> 0) / 4294967296
}

function noise2(x, y, seed) {
  const ix = Math.floor(x)
  const iy = Math.floor(y)
  const fx = x - ix
  const fy = y - iy
  const ux = fx * fx * (3 - 2 * fx)
  const uy = fy * fy * (3 - 2 * fy)

  const a = hash2(ix, iy, seed)
  const b = hash2(ix + 1, iy, seed)
  const c = hash2(ix, iy + 1, seed)
  const d = hash2(ix + 1, iy + 1, seed)

  const top = a + (b - a) * ux
  const bot = c + (d - c) * ux
  return top + (bot - top) * uy
}

function fbm2(x, y, seed) {
  return (
    noise2(x, y, seed) * 0.52 +
    noise2(x * 2.07, y * 2.11, seed + 19) * 0.28 +
    noise2(x * 4.13, y * 4.09, seed + 47) * 0.2
  )
}

/** 把 [lo, hi] 线性拉到 0~1，区间外截断。用来放大噪声的对比。 */
function stretch(v, lo, hi) {
  const t = (v - lo) / (hi - lo)
  return t < 0 ? 0 : t > 1 ? 1 : t
}

// ---------------------------- 密度场 ----------------------------

/**
 * 预计算两份密度场。
 * 每份 = 垂直包络 × 大尺度分布 × 细节纹理，全部烘焙进去，
 * 于是每帧只剩「插值 + 比阈值 + 画方块」。
 */
function buildFields() {
  fieldA = new Float32Array(cols * rows)
  fieldB = new Float32Array(cols * rows)

  const sx = 1 / FIELD_X
  const sy = 1 / FIELD_Y
  const cy = h * CLOUD_Y
  const spread = h * CLOUD_SPREAD

  for (let r = 0; r < rows; r++) {
    const y = r * SPACING
    const ny = (y - cy) / spread

    // 抛物线包络再做一次 smoothstep，边缘消散得自然些
    const e0 = Math.max(0, 1 - ny * ny * 0.9)
    const env = e0 * e0 * (3 - 2 * e0)
    if (env <= 0.002) continue

    for (let c = 0; c < cols; c++) {
      const x = c * SPACING
      const i = r * cols + c
      const fx = x * sx
      const fy = y * sy

      // 关键一步：先对噪声做对比拉伸，再相乘。
      // 原始 fbm 的取值几乎全挤在 0.5 附近（实测 p80 到 p90 只差 0.1），
      // 不拉伸的话阈值化出来是一整片糊的灰 —— 既没有浓核也没有空洞。
      // mac 用很窄的区间拉伸 → 结果接近 0/1 两态，云才是「成块」的，
      // 而不是一层渐变的雾；det 的区间宽一些，负责块内部的纹理与空洞。
      const macA = stretch(fbm2(x * 0.0013 + 11, y * 0.0032 + 5, 17), 0.4, 0.6)
      const detA = stretch(fbm2(fx, fy, 3), 0.35, 0.65)
      fieldA[i] = env * (0.05 + 1.42 * macA) * (0.32 + 1.05 * detA)

      // 第二份用不同偏移，交叉淡入淡出时云团会缓慢变形
      const macB = stretch(fbm2(x * 0.0013 + 41, y * 0.0032 + 23, 53), 0.4, 0.6)
      const detB = stretch(fbm2(fx + 3.1, fy + 1.7, 29), 0.35, 0.65)
      fieldB[i] = env * (0.05 + 1.42 * macB) * (0.32 + 1.05 * detB)
    }
  }
}

function buildJitter() {
  let s = 12345
  const next = () => {
    s = (Math.imul(s, 1664525) + 1013904223) >>> 0
    return s / 4294967296
  }
  jitter = new Float32Array(cols * rows)
  for (let i = 0; i < jitter.length; i++) jitter[i] = next()
}

// ---------------------------- 绘制 ----------------------------

// resize 要重算密度场，代价不小，做个防抖
let resizeTimer = 0

function measureCanvas() {
  const el = canvasRef.value
  if (!el) return false

  const dpr = Math.min(window.devicePixelRatio || 1, 2)
  const nw = el.clientWidth
  const nh = el.clientHeight
  if (nw === w && nh === h) return false

  w = nw
  h = nh
  el.width = Math.round(w * dpr)
  el.height = Math.round(h * dpr)
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)

  cols = Math.ceil(w / SPACING) + 1
  rows = Math.ceil(h / SPACING) + 1
  return true
}

function rebuild() {
  if (!measureCanvas()) return
  buildFields()
  buildJitter()
}

function onResize() {
  clearTimeout(resizeTimer)
  resizeTimer = setTimeout(rebuild, 180)
}

function draw(t) {
  pointer.cx += (pointer.ncx - pointer.cx) * 0.15
  pointer.cy += (pointer.ncy - pointer.cy) * 0.15

  // 整团云缓慢漂移（环境变化，不跟鼠标）
  const ox = Math.sin(t * 0.05) * DRIFT_X
  const oy = Math.cos(t * 0.038) * DRIFT_Y

  ctx.clearRect(0, 0, w, h)

  // 两份场来回交叉，0 → 1 → 0，云团因此缓慢变形
  const morph = 0.5 - 0.5 * Math.cos((t / MORPH_PERIOD) * Math.PI * 2)

  const live = pointer.active
  const reach2 = REACH * REACH
  const inv = 1 / (PEAK - THRESHOLD)

  for (let r = 0; r < rows; r++) {
    const y = r * SPACING

    for (let c = 0; c < cols; c++) {
      const i = r * cols + c

      // 两份密度场插值 —— 每帧唯一的"重量级"运算，就这一下
      const a0 = fieldA[i]
      const d = a0 + (fieldB[i] - a0) * morph
      if (d <= THRESHOLD) continue

      const x = c * SPACING
      const px = x + ox
      const py = y + oy

      // 亮度：阈值→上限线性映射 + gamma + 单点闪烁 + 尺寸微差
      let lit = (d - THRESHOLD) * inv
      if (lit > 1) lit = 1
      lit = Math.pow(lit, GAMMA)
      // 单点闪烁：每个点有自己的明暗节奏，云才不是"冻结"的
      lit *= (1 - TWINKLE) + TWINKLE * (0.5 + 0.5 * Math.sin(t * TWINKLE_SPEED + jitter[i] * 43.7))
      lit *= 0.78 + jitter[i] * 0.3
      if (lit > 1) lit = 1

      // 基础颜色：浓核转青，其余近白
      let rr = RGB_DOT[0]
      let gg = RGB_DOT[1]
      let bb = RGB_DOT[2]
      if (lit > ACCENT_AT) {
        rr = RGB_ACCENT[0]
        gg = RGB_ACCENT[1]
        bb = RGB_ACCENT[2]
      }

      // 光标附近：只**变色**（向青色靠拢），点不位移
      if (live) {
        const rx = px - pointer.cx
        const ry = py - pointer.cy
        const dist2 = rx * rx + ry * ry
        if (dist2 < reach2) {
          const k = 1 - dist2 / reach2
          const m = k * k * HOVER
          rr += (RGB_ACCENT[0] - rr) * m
          gg += (RGB_ACCENT[1] - gg) * m
          bb += (RGB_ACCENT[2] - bb) * m
        }
      }

      const size = DOT - SIZE_JITTER * (1 - lit)

      ctx.fillStyle = `rgba(${rr | 0},${gg | 0},${bb | 0},${lit.toFixed(3)})`
      ctx.fillRect(px, py, size, size)
    }
  }
}

function frame(now) {
  raf = requestAnimationFrame(frame)
  draw((now - startedAt) * 0.001)
}

function onPointerMove(e) {
  pointer.ncx = e.clientX
  pointer.ncy = e.clientY
  pointer.active = true
}

function onPointerLeave() {
  pointer.active = false
  pointer.ncx = -1e4
  pointer.ncy = -1e4
}

function onVisibility() {
  if (document.hidden) {
    cancelAnimationFrame(raf)
    raf = 0
  } else if (!raf) {
    raf = requestAnimationFrame(frame)
  }
}

onMounted(() => {
  ctx = canvasRef.value.getContext('2d')
  measureCanvas()
  buildFields()
  buildJitter()

  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches

  if (reduced) {
    // 系统开了"减少动态效果"时只画静态一帧，不起动画循环。
    // 特意打一行日志：否则在页面上完全看不出是"被降级了"还是"写坏了"。
    console.info(
      '[DotMatrix] 检测到 prefers-reduced-motion，背景只渲染静态一帧。' +
        '想看到动效请在系统里打开动画效果。',
    )
    draw(0)
  } else {
    startedAt = performance.now()
    raf = requestAnimationFrame(frame)
    window.addEventListener('pointermove', onPointerMove, { passive: true })
    document.addEventListener('pointerleave', onPointerLeave)
  }

  window.addEventListener('resize', onResize)
  document.addEventListener('visibilitychange', onVisibility)
})

onBeforeUnmount(() => {
  clearTimeout(resizeTimer)
  cancelAnimationFrame(raf)
  window.removeEventListener('resize', onResize)
  window.removeEventListener('pointermove', onPointerMove)
  document.removeEventListener('pointerleave', onPointerLeave)
  document.removeEventListener('visibilitychange', onVisibility)
})
</script>

<template>
  <canvas ref="canvasRef" class="dotmatrix" aria-hidden="true" />
</template>

<style scoped>
.dotmatrix {
  position: fixed;
  inset: 0;
  z-index: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
}
</style>
