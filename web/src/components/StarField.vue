<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

const canvasRef = ref(null)

let ctx = null
let raf = 0
let w = 0
let h = 0
let dpr = 1
let stars = []

// 光标位置（原始 / 平滑后）
const pointer = { x: -1e4, y: -1e4, active: false }
const smooth = { x: -1e4, y: -1e4 }

// 星座连线的两个阈值
const LINK_RADIUS = 240 // 只有落在这个半径内的星才参与连线
const LINK_MAX = 150 // 两颗星超过这个距离不连

const reduced =
  typeof window !== 'undefined' &&
  window.matchMedia('(prefers-reduced-motion: reduce)').matches

/** 星点数量按面积自适应，DPI 越高越省着点用 */
function buildStars() {
  const count = Math.round(Math.min(280, (w * h) / 8000))
  stars = new Array(count)

  for (let i = 0; i < count; i++) {
    const z = 0.25 + Math.random() * 0.75 // 深度：决定大小、亮度、视差幅度
    stars[i] = {
      x: Math.random() * w,
      y: Math.random() * h,
      z,
      r: 0.35 + z * 1.3,
      a: 0.22 + z * 0.62,
      tw: Math.random() * Math.PI * 2, // 闪烁相位
      tws: 0.4 + Math.random() * 1.15, // 闪烁速度
      vx: (Math.random() - 0.5) * 0.04 * z,
      vy: (Math.random() - 0.5) * 0.04 * z,
    }
  }
}

function resize() {
  const el = canvasRef.value
  if (!el) return

  dpr = Math.min(window.devicePixelRatio || 1, 2)
  w = el.clientWidth
  h = el.clientHeight

  el.width = Math.round(w * dpr)
  el.height = Math.round(h * dpr)
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)

  buildStars()
}

function frame(t) {
  raf = requestAnimationFrame(frame)
  if (!ctx) return

  ctx.clearRect(0, 0, w, h)

  // 光标缓动，避免视差跟着手抖
  smooth.x += (pointer.x - smooth.x) * 0.12
  smooth.y += (pointer.y - smooth.y) * 0.12

  const ox = (smooth.x - w / 2) * 0.014
  const oy = (smooth.y - h / 2) * 0.014

  const near = []

  for (let i = 0; i < stars.length; i++) {
    const s = stars[i]

    if (!reduced) {
      s.x += s.vx
      s.y += s.vy
      if (s.x < -8) s.x = w + 8
      else if (s.x > w + 8) s.x = -8
      if (s.y < -8) s.y = h + 8
      else if (s.y > h + 8) s.y = -8
    }

    const x = s.x + ox * s.z
    const y = s.y + oy * s.z

    const twinkle = reduced ? 1 : 0.55 + 0.45 * Math.sin(t * 0.001 * s.tws + s.tw)

    ctx.beginPath()
    ctx.arc(x, y, s.r, 0, Math.PI * 2)
    ctx.fillStyle = `rgba(226,236,255,${(s.a * twinkle).toFixed(3)})`
    ctx.fill()

    if (pointer.active) {
      const dx = x - smooth.x
      const dy = y - smooth.y
      if (dx * dx + dy * dy < LINK_RADIUS * LINK_RADIUS) near.push(x, y)
    }
  }

  // 星座连线：光标附近的星两两相连，越近越亮
  if (near.length >= 4) {
    const n = near.length / 2
    for (let i = 0; i < n; i++) {
      const ax = near[i * 2]
      const ay = near[i * 2 + 1]
      for (let j = i + 1; j < n; j++) {
        const dx = ax - near[j * 2]
        const dy = ay - near[j * 2 + 1]
        const d = Math.sqrt(dx * dx + dy * dy)
        if (d > LINK_MAX) continue

        ctx.beginPath()
        ctx.moveTo(ax, ay)
        ctx.lineTo(near[j * 2], near[j * 2 + 1])
        ctx.strokeStyle = `rgba(118,198,255,${((1 - d / LINK_MAX) * 0.32).toFixed(3)})`
        ctx.lineWidth = 0.7
        ctx.stroke()
      }
    }
  }
}

function onPointerMove(e) {
  pointer.x = e.clientX
  pointer.y = e.clientY
  pointer.active = true
}

function onPointerLeave() {
  pointer.active = false
  pointer.x = -1e4
  pointer.y = -1e4
}

function onVisibility() {
  // 切到后台就别烧 CPU 了
  if (document.hidden) {
    cancelAnimationFrame(raf)
    raf = 0
  } else if (!raf) {
    raf = requestAnimationFrame(frame)
  }
}

onMounted(() => {
  const el = canvasRef.value
  ctx = el.getContext('2d')

  resize()
  raf = requestAnimationFrame(frame)

  window.addEventListener('resize', resize)
  window.addEventListener('pointermove', onPointerMove, { passive: true })
  window.addEventListener('pointerleave', onPointerLeave)
  document.addEventListener('visibilitychange', onVisibility)
})

onBeforeUnmount(() => {
  cancelAnimationFrame(raf)
  window.removeEventListener('resize', resize)
  window.removeEventListener('pointermove', onPointerMove)
  window.removeEventListener('pointerleave', onPointerLeave)
  document.removeEventListener('visibilitychange', onVisibility)
})
</script>

<template>
  <canvas ref="canvasRef" class="starfield" aria-hidden="true" />
</template>

<style scoped>
.starfield {
  position: fixed;
  inset: 0;
  z-index: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
}
</style>
