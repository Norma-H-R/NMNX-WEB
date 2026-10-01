<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * 点阵地形背景。
 *
 * 纯黑底上，由小方块构成一条横贯页面的"山脊"，中部密、两侧散，
 * 点有明暗层次所以有体积感。
 *
 * 动效分四层叠出来，缺一层就会显得很"呆"：
 *   1. 地形演进 —— 两条脊线各自缓慢漂移，形态持续变化，不是整体平移；
 *   2. 行波     —— 一道正弦波沿 x 扫过去，是"活着"最直观的来源；
 *   3. 呼吸     —— 整条脊线上下缓慢起伏，幅度小但要一直有；
 *   4. 闪烁     —— 每个点按自己的相位明暗脉动，打散"一整块在动"的机械感。
 *
 * 再加上鼠标交互：光标附近的地形被顶起来，局部变亮，整层还有缓动视差。
 *
 * 实现取舍（不引任何库，纯 canvas 手写）：
 *   - 山脊是一维函数，每列只算一次高度。地形只跟 x 有关，噪声采样从
 *     "几万次/帧"降到"两百多次/帧"。
 *   - 每列只遍历脊线上下很窄的一段，屏幕大部分是黑的，不浪费绘制。
 *   - 深度靠两条脊线做：远的那条更淡更薄、起伏更小。
 *   - 点的位置抖动由哈希算出（逐帧稳定），用 Math.random 会导致每帧乱闪。
 *   - 切到后台标签页停掉 rAF。
 */

// ---------------------------- 可调参数 ----------------------------

const SPACING = 9 // 点阵间距（CSS 像素）
const DOT = 1.7 // 点的基础边长

const RIDGE_Y = 0.33 // 主脊线在视口高度上的位置
const RIDGE_AMP = 0.14 // 脊线起伏幅度（相对视口高度）
const RIDGE_THICK = 0.052 // 主脊线厚度（相对视口高度）

// 动效强度
const DRIFT_A = 0.09 // 主脊线漂移速度（噪声单位/秒）
const DRIFT_B = 0.07 // 次脊线漂移速度，与主脊线错开才不会像整体平移
const WAVE_SPEED = 1.15 // 行波速度
const WAVE_AMP = 0.22 // 行波幅度（相对起伏幅度）
const BREATH_SPEED = 0.55 // 呼吸速度
const BREATH_AMP = 0.16 // 呼吸幅度
const TWINKLE = 0.3 // 闪烁深度，0 = 不闪

// 鼠标交互
const PARALLAX_X = 18 // 视差强度（像素）
const PARALLAX_Y = 10
const CURSOR_REACH = 260 // 光标影响半径（像素）
const CURSOR_LIFT = 52 // 光标把地形顶起的高度（像素）
const CURSOR_BOOST = 0.75 // 光标附近点的额外亮度

// 颜色与 main.css 里的设计变量保持一致，改主题色时两边一起改
const RGB_DOT = '233, 236, 245' // = --text
const RGB_ACCENT = '110, 231, 255' // = --cyan
const ACCENT_AT = 0.66 // 强调色的噪声阈值，越高越少

// ---------------------------- 状态 ----------------------------

const canvasRef = ref(null)

let ctx = null
let raf = 0
let w = 0
let h = 0
let cols = 0
let rows = 0
let jitter = null // Float32Array，逐点抖动 + 闪烁相位
let lastT = 0

const pointer = {
  // 归一化视差目标（-1 ~ 1）与缓动值
  tx: 0,
  ty: 0,
  x: 0,
  y: 0,
  // 像素坐标的缓动值，用于"顶起地形"
  cxp: -1e4,
  cyp: -1e4,
  nxp: -1e4,
  nyp: -1e4,
  active: false,
}

// ---------------------------- 噪声 ----------------------------

function hash(i, seed) {
  const s = Math.sin(i * 127.1 + seed * 311.7) * 43758.5453123
  return s - Math.floor(s)
}

function noise1(x, seed) {
  const i = Math.floor(x)
  const f = x - i
  const u = f * f * (3 - 2 * f) // smoothstep，避免折线感
  return hash(i, seed) * (1 - u) + hash(i + 1, seed) * u
}

// 三个八度叠加，系数和为 1，结果落在 0~1
function fbm1(x, seed) {
  return (
    noise1(x, seed) * 0.5 +
    noise1(x * 2.03, seed + 13.7) * 0.28 +
    noise1(x * 4.11, seed + 41.3) * 0.22
  )
}

// ---------------------------- 绘制 ----------------------------

function buildJitter() {
  // 线性同余生成器，保证 resize 后纹理位置稳定
  let s = 1
  const next = () => {
    s = (s * 1664525 + 1013904223) % 4294967296
    return s / 4294967296
  }
  jitter = new Float32Array(cols * rows)
  for (let i = 0; i < jitter.length; i++) jitter[i] = next()
}

function resize() {
  const el = canvasRef.value
  if (!el) return

  const dpr = Math.min(window.devicePixelRatio || 1, 2)
  w = el.clientWidth
  h = el.clientHeight

  el.width = Math.round(w * dpr)
  el.height = Math.round(h * dpr)
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)

  cols = Math.ceil(w / SPACING) + 1
  rows = Math.ceil(h / SPACING) + 1
  buildJitter()
}

function draw(t) {
  // 光标缓动：视差慢一点，顶起地形快一点，手感才跟得上
  pointer.x += (pointer.tx - pointer.x) * 0.045
  pointer.y += (pointer.ty - pointer.y) * 0.045
  pointer.cxp += (pointer.nxp - pointer.cxp) * 0.16
  pointer.cyp += (pointer.nyp - pointer.cyp) * 0.16

  const ox = pointer.x * PARALLAX_X
  const oy = pointer.y * PARALLAX_Y

  ctx.clearRect(0, 0, w, h)

  const ridgeY = h * RIDGE_Y + oy
  const amp = h * RIDGE_AMP
  const th1 = h * RIDGE_THICK
  const th2 = th1 * 0.72

  const breath = Math.sin(t * BREATH_SPEED) * amp * BREATH_AMP
  const cursorLive = pointer.active
  const reach2 = CURSOR_REACH * CURSOR_REACH

  for (let c = 0; c < cols; c++) {
    const x = c * SPACING

    // 两条脊线用不同的时间漂移，整体就不是平移，而是在缓慢变形
    const n1 = fbm1(x * 0.0032 + t * DRIFT_A, 1.0)
    const n2 = fbm1(x * 0.0044 - t * DRIFT_B, 5.0)

    // 行波：沿 x 扫出去的一道起伏，是"在动"最直接的信号
    const wave = Math.sin(x * 0.006 - t * WAVE_SPEED) * amp * WAVE_AMP

    let y1 = ridgeY + (n1 - 0.5) * 2 * amp + wave + breath
    let y2 = ridgeY + h * 0.078 + (n2 - 0.5) * 2 * amp * 0.85 + breath * 0.6

    // 光标把附近地形顶起来
    let cursorBoost = 0
    if (cursorLive) {
      const dx = x - pointer.cxp
      const k = 1 / (1 + (dx * dx) / reach2)
      y1 -= k * CURSOR_LIFT * 0.5
      y2 -= k * CURSOR_LIFT * 0.3
      cursorBoost = k
    }

    const accent = fbm1(x * 0.0055 + t * 0.05, 9.0) > ACCENT_AT
    const dx = x + ox

    let r = Math.floor(Math.min(y1 - th1, y2 - th2) / SPACING)
    const rEnd = Math.ceil(Math.max(y1 + th1, y2 + th2) / SPACING)
    if (r < 0) r = 0

    for (; r <= rEnd && r < rows; r++) {
      const y = r * SPACING

      // 亮度沿垂直方向衰减，边缘自然消散
      let a = 0
      const d1 = Math.abs(y - y1) / th1
      if (d1 < 1) a = (1 - d1) * (1 - d1)

      const d2 = Math.abs(y - y2) / th2
      if (d2 < 1) {
        const a2 = (1 - d2) * (1 - d2) * 0.42
        if (a2 > a) a = a2
      }

      if (a < 0.035) continue

      const j = jitter[r * cols + c]

      // 闪烁：每个点按自己的相位脉动，打散"整块一起动"的机械感
      const tw = 1 - TWINKLE + TWINKLE * Math.sin(t * (0.7 + j * 1.7) + j * 43.0)

      let alpha = a * (0.52 + j * 0.48) * tw

      // 光标附近额外提亮
      if (cursorBoost > 0.02) alpha *= 1 + cursorBoost * CURSOR_BOOST

      const size = DOT * (0.7 + a * 0.75)

      ctx.fillStyle = accent
        ? `rgba(${RGB_ACCENT},${alpha.toFixed(3)})`
        : `rgba(${RGB_DOT},${alpha.toFixed(3)})`

      ctx.fillRect(dx, y + oy * 0.5, size, size)
    }
  }
}

function frame(now) {
  raf = requestAnimationFrame(frame)
  lastT = now * 0.001
  draw(lastT)
}

function onPointerMove(e) {
  pointer.tx = (e.clientX / w) * 2 - 1
  pointer.ty = (e.clientY / h) * 2 - 1
  pointer.nxp = e.clientX
  pointer.nyp = e.clientY
  pointer.active = true
}

function onPointerLeave() {
  pointer.active = false
  pointer.nxp = -1e4
  pointer.nyp = -1e4
  pointer.tx = 0
  pointer.ty = 0
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
  resize()

  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches

  if (reduced) {
    // 系统开了"减少动态效果"时只画静态一帧，不起动画循环。
    // 这里特意打一行日志：否则在页面上完全看不出是"被降级了"还是"写坏了"。
    console.info(
      '[DotMatrix] 检测到 prefers-reduced-motion，背景只渲染静态一帧。' +
        '想看到动效请在系统里打开动画效果。',
    )
    draw(0)
  } else {
    raf = requestAnimationFrame(frame)
    window.addEventListener('pointermove', onPointerMove, { passive: true })
    document.addEventListener('pointerleave', onPointerLeave)
  }

  window.addEventListener('resize', resize)
  document.addEventListener('visibilitychange', onVisibility)
})

onBeforeUnmount(() => {
  cancelAnimationFrame(raf)
  window.removeEventListener('resize', resize)
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
