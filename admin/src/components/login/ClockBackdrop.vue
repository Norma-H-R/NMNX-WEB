<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * 巨型数字时钟背景（Canvas 手绘，零依赖）。
 *
 * 为什么不用 DOM/CSS 排字：
 *   我们要的是"LED 大屏"的点阵质感 —— 每个数字由一个个独立格点拼成，
 *   格点要能逐点点亮、熄灭时留余晖、整块随秒脉冲。DOM 做不到逐点控制，
 *   除非把每个点渲染成一个元素（一屏 200+ 节点，动效一开必掉帧）。
 *
 * 为什么不用外部字体 / 图片：
 *   官网为保首屏速度不引 Google Fonts（见 face/app/assets/css/main.css 的说明），
 *   这里同样坚持零网络依赖 —— 点阵字模是硬编码在下面的常量，无 FOUT、无 404。
 *
 * 坐标约定：
 *   所有绘制都用 CSS 像素坐标。DPR 缩放一次性 setTransform 解决，
 *   这样 resize / 高清屏都不用改绘制代码里的任何数字。
 */

// ── 5×7 点阵字模：'1' 为亮点 ──────────────────────────────────────────
const GLYPHS: Record<string, string[]> = {
  '0': ['01110', '10001', '10011', '10101', '11001', '10001', '01110'],
  '1': ['00100', '01100', '00100', '00100', '00100', '00100', '01110'],
  '2': ['01110', '10001', '00001', '00010', '00100', '01000', '11111'],
  '3': ['11111', '00010', '00100', '00010', '00001', '10001', '01110'],
  '4': ['00010', '00110', '01010', '10010', '11111', '00010', '00010'],
  '5': ['11111', '10000', '11110', '00001', '00001', '10001', '01110'],
  '6': ['00110', '01000', '10000', '11110', '10001', '10001', '01110'],
  '7': ['11111', '00001', '00010', '00100', '01000', '01000', '01000'],
  '8': ['01110', '10001', '10001', '01110', '10001', '10001', '01110'],
  '9': ['01110', '10001', '10001', '01111', '00001', '00010', '01100'],
  // 冒号只用 3 列（上 1/3 与下 1/3 各一点），比数字窄，省下的宽度还给数字
  ':': ['000', '010', '000', '000', '010', '000', '000'],
}

const CHAR_GAP = 1 // 字符间空白列数
const ROWS = 7 // 字模高度
const WIDTH_RATIO = 0.88 // 时钟占视口宽度的比例
const HEIGHT_RATIO = 0.5 // 时钟最多占视口高度的比例

// 渐变色取自官网设计变量：--cyan → --violet
// as const 是必要的：项目开了 noUncheckedIndexedAccess，普通 number[] 取下标会得到 number | undefined
const C_FROM = [110, 231, 255] as const
const C_TO = [167, 139, 250] as const

const canvasRef = ref<HTMLCanvasElement | null>(null)

type Dot = { col: number; row: number; level: number }
type Star = { x: number; y: number; z: number; r: number; phase: number }

let ctx: CanvasRenderingContext2D | null = null
let raf = 0
let dpr = 1
let W = 0
let H = 0
let last = 0
let current = ''
let lit = new Set<string>()
let stars: Star[] = []

/**
 * 辉光贴图：一张白色径向渐变，只构建一次，之后每帧直接 drawImage 缩放贴上去。
 *
 * 这是整个组件最要紧的一处优化。原来给每个点设 `ctx.shadowBlur = cell * 0.85`，
 * 等于**每帧对上百个点各做一次高斯模糊**，半径还有 30px+ —— 单这一项就能把
 * 登录页的帧率从 60 压到 20 出头（实测）。换成贴图后模糊成本为零，
 * 而且观感更接近真实发光：光本来就偏白，原来带颜色的阴影反而发闷。
 */
let glowSprite: HTMLCanvasElement | null = null

/**
 * 每列的颜色字符串，随 cols 变化重建。
 * 原来每帧每个点都要拼一次 `rgb(r, g, b)`，上百次字符串拼接 + 解析；
 * 缓存成数组后每帧只做一次数组取值。
 */
let columnColors: string[] = []

/**
 * 点的生命周期用一个 Map 承载（key = "col-row"），而不是每帧重建数组。
 * 这样数字变化时，只有真正增减的点在动，同一个点的 alpha 连续变化，
 * 才能出现"亮得干脆、灭得拖尾"的 LED 余晖，而不是整屏一起闪。
 */
const dots = new Map<string, Dot>()
const layout = { cell: 0, cols: 0, x: 0, y: 0 }

const reduced =
  typeof window !== 'undefined' && typeof window.matchMedia === 'function'
    ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
    : false

const pad = (n: number) => (n < 10 ? `0${n}` : String(n))
const stamp = (d = new Date()) => `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`

// ── 布局 ─────────────────────────────────────────────────────────────

function glyphCols(ch: string) {
  return GLYPHS[ch]?.[0]?.length ?? 0
}

function measure(text: string) {
  let cols = 0
  for (const ch of text) {
    if (cols > 0) cols += CHAR_GAP
    cols += glyphCols(ch)
  }
  return cols
}

/**
 * 把当前时间文本落成布局 + 亮点集合。
 * 只在"秒变化"或"尺寸变化"时调用 —— 每帧重算是浪费。
 */
function applyText(text: string) {
  current = text
  const cols = measure(text)
  const cell = Math.min((W * WIDTH_RATIO) / cols, (H * HEIGHT_RATIO) / ROWS)

  layout.cell = cell
  layout.cols = cols
  layout.x = (W - cols * cell) / 2
  layout.y = (H - ROWS * cell) / 2

  const next = new Set<string>()
  let cursor = 0
  for (const ch of text) {
    const rows = GLYPHS[ch]
    if (rows) {
      for (let r = 0; r < rows.length; r += 1) {
        const line = rows[r] ?? ''
        for (let c = 0; c < line.length; c += 1) {
          if (line[c] === '1') next.add(`${cursor + c}-${r}`)
        }
      }
    }
    cursor += glyphCols(ch) + CHAR_GAP
  }
  lit = next
}

function makeStars() {
  const count = Math.round(Math.min(240, Math.max(60, (W * H) / 11000)))
  stars = Array.from({ length: count }, () => ({
    x: Math.random(),
    y: Math.random(),
    z: 0.15 + Math.random() * 0.85, // 深度：越大越"近"，动得越快、也越亮
    r: 0.4 + Math.random() * 1.4,
    phase: Math.random() * Math.PI * 2,
  }))
}

function resize() {
  const el = canvasRef.value
  if (!el) return

  W = el.clientWidth
  H = el.clientHeight
  if (W === 0 || H === 0) return

  // 上限 1.5（原来是 2）：高 DPI 屏上的像素数降到 56%，
  // 而点阵是一堆色块、没有 1px 细线，肉眼看不出差别
  dpr = Math.min(1.5, window.devicePixelRatio || 1)
  el.width = Math.round(W * dpr)
  el.height = Math.round(H * dpr)

  ctx = el.getContext('2d')
  if (!ctx) return
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)

  makeStars()
  applyText(current || stamp())

  // 这两样都只跟尺寸有关，放在 resize 里重建一次，绘制时直接用
  buildColumnColors(layout.cols)
  buildGlowSprite()
}

// ── 绘制 ─────────────────────────────────────────────────────────────

/** 按列位置预计算"青 → 紫"的颜色字符串 */
function buildColumnColors(cols: number) {
  const denom = Math.max(1, cols - 1)
  columnColors = Array.from({ length: cols }, (_, col) => {
    const t = col / denom
    const r = Math.round(C_FROM[0] + (C_TO[0] - C_FROM[0]) * t)
    const g = Math.round(C_FROM[1] + (C_TO[1] - C_FROM[1]) * t)
    const b = Math.round(C_FROM[2] + (C_TO[2] - C_FROM[2]) * t)
    return `rgb(${r}, ${g}, ${b})`
  })
}

/** 构建辉光贴图（只需一次） */
function buildGlowSprite() {
  if (glowSprite) return

  const size = 64
  const el = document.createElement('canvas')
  el.width = size
  el.height = size

  const g = el.getContext('2d')
  if (!g) return

  const grad = g.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size / 2)
  grad.addColorStop(0, 'rgba(255, 255, 255, 1)')
  grad.addColorStop(0.32, 'rgba(255, 255, 255, 0.45)')
  grad.addColorStop(1, 'rgba(255, 255, 255, 0)')
  g.fillStyle = grad
  g.fillRect(0, 0, size, size)

  glowSprite = el
}

function roundRectPath(
  c: CanvasRenderingContext2D,
  x: number,
  y: number,
  w: number,
  h: number,
  r: number,
) {
  c.beginPath()
  c.moveTo(x + r, y)
  c.lineTo(x + w - r, y)
  c.quadraticCurveTo(x + w, y, x + w, y + r)
  c.lineTo(x + w, y + h - r)
  c.quadraticCurveTo(x + w, y + h, x + w - r, y + h)
  c.lineTo(x + r, y + h)
  c.quadraticCurveTo(x, y + h, x, y + h - r)
  c.lineTo(x, y + r)
  c.quadraticCurveTo(x, y, x + r, y)
  c.closePath()
}

function drawStars(dt: number, now: number) {
  if (!ctx) return
  for (const s of stars) {
    // 缓缓上浮：z 大的（更"近"）走得更快，形成纵深感
    s.y -= 0.0006 * s.z * dt
    if (s.y < -0.05) s.y = 1.05

    const px = s.x * W
    const py = s.y * H
    const twinkle = reduced ? 0.85 : 0.55 + 0.45 * Math.sin(now / 900 + s.phase)

    ctx.globalAlpha = (0.16 + 0.6 * s.z) * twinkle
    ctx.fillStyle = s.z > 0.78 ? '#eaf6ff' : '#8fe6ff'
    ctx.beginPath()
    ctx.arc(px, py, s.r * s.z * 1.6, 0, Math.PI * 2)
    ctx.fill()
  }
}

function drawClock(dt: number) {
  if (!ctx || layout.cell <= 0) return

  const { cell, x: ox, y: oy } = layout
  const size = cell * 0.66
  const radius = size * 0.3
  const inset = (cell - size) / 2

  // 1) 新点入场（level 从 0 开始 → 自然淡入）
  for (const key of lit) {
    if (!dots.has(key)) {
      const parts = key.split('-')
      dots.set(key, { col: Number(parts[0]), row: Number(parts[1]), level: 0 })
    }
  }

  // 2) 收敛：亮得干脆、灭得拖尾，灭干净的点直接回收
  for (const [key, dot] of dots) {
    const target = lit.has(key) ? 1 : 0
    const k = target > 0 ? 0.34 : 0.12
    dot.level += (target - dot.level) * (1 - Math.pow(1 - k, dt))
    if (target === 0 && dot.level < 0.006) dots.delete(key)
  }

  // 3) 出图：先贴一层辉光（预渲染贴图，零模糊成本），再压一块实心色块。
  //    颜色从 columnColors 里取，不再每点拼字符串
  const glowSize = size * 3.4
  for (const dot of dots.values()) {
    if (dot.level < 0.006) continue

    const left = ox + dot.col * cell
    const top = oy + dot.row * cell

    if (glowSprite) {
      ctx.globalAlpha = Math.min(1, dot.level * 0.3)
      ctx.drawImage(
        glowSprite,
        left + cell / 2 - glowSize / 2,
        top + cell / 2 - glowSize / 2,
        glowSize,
        glowSize,
      )
    }

    ctx.globalAlpha = Math.min(1, dot.level * 0.92)
    ctx.fillStyle = columnColors[dot.col] ?? '#6ee7ff'
    roundRectPath(ctx, left + inset, top + inset, size, size, radius)
    ctx.fill()
  }
}

function frame(now: number) {
  raf = requestAnimationFrame(frame)
  if (!ctx) return

  // 帧归一化：把 delta 折算成"60fps 下了几帧"，不同刷新率下动画速度一致
  const dt = last ? Math.min(64, now - last) / 16.667 : 1
  last = now

  const text = stamp()
  if (text !== current) applyText(text)

  ctx.clearRect(0, 0, W, H)
  ctx.globalCompositeOperation = 'lighter' // 叠加发光，点密集处自然过曝成白
  drawStars(dt, now)
  drawClock(dt)
  ctx.globalCompositeOperation = 'source-over'
  ctx.shadowBlur = 0
  ctx.globalAlpha = 1
}

onMounted(() => {
  resize()
  window.addEventListener('resize', resize)
  raf = requestAnimationFrame(frame)
})

onBeforeUnmount(() => {
  cancelAnimationFrame(raf)
  window.removeEventListener('resize', resize)
})
</script>

<template>
  <canvas ref="canvasRef" class="clock-canvas" aria-hidden="true" />
</template>

<style scoped>
.clock-canvas {
  display: block;
  width: 100%;
  height: 100%;
}
</style>
