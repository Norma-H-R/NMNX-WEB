<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * 点阵云团背景（对齐 trae.ai 的真实实现，扒其 GLSL 反推）。
 *
 * 它的点阵不是"画很多点"，而是三层合成：
 *   1. 底层一片**流动的流体噪声**：fbm + 域扭曲（domain warp），时间只做垂直漂移；
 *   2. 中层**像素化**：把流体切成网格方块，每块的采样点被一个静态流场推开；
 *   3. 上层**阈值 + 随机上色**：亮度低于（带每块随机抖动的）阈值就出背景，
 *      高于阈值就出点；颜色按每块随机数在 白/强调色 之间选。
 * 鼠标只做**颜色交换**，不影响流动、不产生位移（其源码注释明确写了这点）。
 *
 * Canvas 2D 版等价实现：
 *   - DF：一张**平铺（周期 P）**的 fbm 密度场，缩放时算一次，存 Float32Array；
 *   - warpX/warpY：静态流场扭曲（相干，不随帧变），缩放时算一次；
 *   - 每帧只做：采样坐标 = 网格 + 流场扭曲 + 垂直滚动(phase)，对 DF 双线性采样，
 *     再比阈值 + 上色。每帧最重的工作就是一次双线性查表，很轻。
 */

// ---------------------------- 可调参数 ----------------------------

const SPACING = 6 // 格子间距（CSS 像素）
const DOT = 5 // 方块边长，比间距小一点，方块之间留暗缝才有"点阵"感
const SIZE_JITTER = 1.3 // 方块尺寸随机浮动

const CLOUD_Y = 0.38 // 云团中心在视口高度上的位置
const CLOUD_SPREAD = 0.34 // 云团的垂直扩散半径（相对视口高度）

// 流动（核心，之前漏掉的一环）
const FLOW_SPEED = 1.4 // 垂直流动速度（场单元/秒）
const WARP_X = 3.0 // 水平流场扭曲幅度（场单元）
const WARP_Y = 5.0 // 垂直流场扭曲幅度（场单元，更大 → 更偏纵向流动）

const P = 256 // 平铺密度场的周期（场单元）

// 阈值与亮度
const THRESHOLD = 0.55
const GAMMA = 0.8
const JITTER_RANGE = 0.14 // 每点阈值随机抖动，制造噪点边缘与空洞

// 鼠标：只变色，不位移
const REACH = 220
const HOVER = 0.9

// 颜色与 main.css 里的设计变量保持一致（数组便于插值）
const RGB_DOT = [226, 236, 245] // 接近 --text
const RGB_ACCENT = [110, 231, 255] // = --cyan
const ACCENT_AT = 0.8 // 亮度高于此值转强调色，青色只出现在最浓的核里

// ---------------------------- 状态 ----------------------------

const canvasRef = ref(null)

let ctx = null
let raf = 0
let w = 0
let h = 0
let dpr = 1
let cols = 0
let rows = 0
let DF = null // 平铺密度场 P×P
let warpX = null // 静态水平流场扭曲
let warpY = null // 静态垂直流场扭曲
let jitter = null // 每点 0~1 随机（阈值抖动 + 尺寸）
let envRow = null // 每行垂直包络
let startedAt = 0

const pointer = {
  cx: -1e4,
  cy: -1e4,
  ncx: -1e4,
  ncy: -1e4,
  active: false,
}

// ---------------------------- 噪声 ----------------------------

// 整数哈希，Math.imul 做 32 位乘法避免精度问题
function hash2(ix, iy, seed) {
  let n = Math.imul(ix, 374761393) + Math.imul(iy, 668265263) + Math.imul(seed, 1442695041)
  n = Math.imul(n ^ (n >>> 13), 1274126177)
  n ^= n >>> 16
  return (n >>> 0) / 4294967296
}

const smooth = (f) => f * f * (3 - 2 * f)

// 普通值噪声（非周期），给流场用
function noise2(x, y, seed) {
  const ix = Math.floor(x)
  const iy = Math.floor(y)
  const fx = x - ix
  const fy = y - iy
  const ux = smooth(fx)
  const uy = smooth(fy)
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

// 周期值噪声：u,v ∈ [0,1)，周期 1。用于构建可平铺的密度场。
function pnoise1(u, v, seed) {
  const su = u * P
  const sv = v * P
  const ix = Math.floor(su)
  const iy = Math.floor(sv)
  const fx = su - ix
  const fy = sv - iy
  const ux = smooth(fx)
  const uy = smooth(fy)
  const X0 = ix % P
  const X1 = (ix + 1) % P
  const Y0 = iy % P
  const Y1 = (iy + 1) % P
  const a = hash2(X0, Y0, seed)
  const b = hash2(X1, Y0, seed)
  const c = hash2(X0, Y1, seed)
  const d = hash2(X1, Y1, seed)
  return a + (b - a) * ux + (c - a) * uy + (a - b - c + d) * ux * uy
}

// 周期 fbm：频率取整数周期数，保证整个场以 P 为周期无缝平铺
const K1 = 2
const K2 = 6
const K3 = 16
function pfbm2(px, py, seed) {
  const u = px / P
  const v = py / P
  return (
    pnoise1(u * K1, v * K1, seed) * 0.5 +
    pnoise1(u * K2, v * K2, seed + 19) * 0.3 +
    pnoise1(u * K3, v * K3, seed + 47) * 0.2
  )
}

/** 把 [lo, hi] 线性拉到 0~1，区间外截断。放大噪声对比，否则糊成一片灰。 */
function stretch(v, lo, hi) {
  const t = (v - lo) / (hi - lo)
  return t < 0 ? 0 : t > 1 ? 1 : t
}

// ---------------------------- 构建 ----------------------------

function buildDensity() {
  DF = new Float32Array(P * P)
  for (let py = 0; py < P; py++) {
    for (let px = 0; px < P; px++) {
      // 域扭曲：用一个相干流场把采样点推开，自然挤出浓核与空洞
      const wx = (fbm2(px * 0.03, py * 0.03, 100) * 2 - 1) * 6
      const wy = (fbm2(px * 0.03 + 7, py * 0.03 + 3, 101) * 2 - 1) * 6
      DF[py * P + px] = stretch(pfbm2(px + wx, py + wy, 5), 0.35, 0.65)
    }
  }
}

function buildWarp() {
  warpX = new Float32Array(cols * rows)
  warpY = new Float32Array(cols * rows)
  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      const i = r * cols + c
      const fx = c * 0.06
      const fy = r * 0.08
      warpX[i] = (fbm2(fx, fy, 21) * 2 - 1) * WARP_X
      warpY[i] = (fbm2(fx + 9, fy + 3, 22) * 2 - 1) * WARP_Y
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

function buildEnvelope() {
  envRow = new Float32Array(rows)
  const cy = h * CLOUD_Y
  const spread = h * CLOUD_SPREAD
  for (let r = 0; r < rows; r++) {
    const ny = (r * SPACING - cy) / spread
    const e0 = Math.max(0, 1 - ny * ny * 0.9)
    envRow[r] = e0 * e0 * (3 - 2 * e0)
  }
}

// resize 要重算密度场，代价不小，做防抖
let resizeTimer = 0

function measureCanvas() {
  const el = canvasRef.value
  if (!el) return false
  const ndpr = Math.min(window.devicePixelRatio || 1, 2)
  const nw = el.clientWidth
  const nh = el.clientHeight
  if (nw === w && nh === h && ndpr === dpr) return false

  w = nw
  h = nh
  dpr = ndpr
  el.width = Math.round(w * dpr)
  el.height = Math.round(h * dpr)
  ctx = el.getContext('2d')
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)

  cols = Math.ceil(w / SPACING) + 1
  rows = Math.ceil(h / SPACING) + 1
  return true
}

function rebuild() {
  if (!measureCanvas()) return
  buildDensity()
  buildWarp()
  buildJitter()
  buildEnvelope()
}

function onResize() {
  clearTimeout(resizeTimer)
  resizeTimer = setTimeout(rebuild, 180)
}

// ---------------------------- 绘制 ----------------------------

function draw(t) {
  pointer.cx += (pointer.ncx - pointer.cx) * 0.15
  pointer.cy += (pointer.ncy - pointer.cy) * 0.15

  // 垂直流动：密度场整体向上滚，配合流场扭曲，像液体一样流
  const phase = t * FLOW_SPEED
  const live = pointer.active
  const reach2 = REACH * REACH

  ctx.clearRect(0, 0, w, h)

  for (let r = 0; r < rows; r++) {
    const env = envRow[r]
    if (env <= 0.002) continue
    const y = r * SPACING

    for (let c = 0; c < cols; c++) {
      const i = r * cols + c

      // 采样坐标 = 网格 + 静态流场扭曲 + 垂直滚动
      let sx = c + warpX[i]
      let sy = r + warpY[i] + phase

      // 归一化到 [0,P) 后对平铺密度场做双线性采样
      let sx0 = Math.floor(sx)
      let sy0 = Math.floor(sy)
      const fx = sx - sx0
      const fy = sy - sy0
      sx0 %= P
      if (sx0 < 0) sx0 += P
      sy0 %= P
      if (sy0 < 0) sy0 += P
      const sx1 = sx0 === P - 1 ? 0 : sx0 + 1
      const sy1 = sy0 === P - 1 ? 0 : sy0 + 1
      const a = DF[sy0 * P + sx0]
      const b = DF[sy0 * P + sx1]
      const c2 = DF[sy1 * P + sx0]
      const d2 = DF[sy1 * P + sx1]
      let density = a + (b - a) * fx + (c2 - a) * fy + (a - b - c2 + d2) * fx * fy

      density *= env
      if (density <= 0) continue

      // 阈值 + 每点随机抖动 → 噪点边缘与空洞
      const thr = THRESHOLD - jitter[i] * JITTER_RANGE
      if (density <= thr) continue

      let lit = (density - thr) / (1 - thr)
      if (lit > 1) lit = 1
      lit = Math.pow(lit, GAMMA) * (0.78 + jitter[i] * 0.3)
      if (lit > 1) lit = 1

      const px = c * SPACING
      const py = y

      // 基础颜色：浓核转青，其余近白
      let rr = RGB_DOT[0]
      let gg = RGB_DOT[1]
      let bb = RGB_DOT[2]
      if (lit > ACCENT_AT) {
        rr = RGB_ACCENT[0]
        gg = RGB_ACCENT[1]
        bb = RGB_ACCENT[2]
      }

      // 光标：只做颜色交换（向青色靠拢），不位移
      if (live) {
        const rx = px - pointer.cx
        const ry = py - pointer.cy
        const d = rx * rx + ry * ry
        if (d < reach2) {
          const k = 1 - d / reach2
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
  rebuild()

  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches
  if (reduced) {
    console.info('[DotMatrix] 检测到 prefers-reduced-motion，背景只渲染静态一帧。')
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
