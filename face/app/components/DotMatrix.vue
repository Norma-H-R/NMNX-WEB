<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * 点阵云团背景 —— WebGL2 版。
 *
 * 与参考站（trae.ai）的点阵同一套技术路线（流体 → 像素化 → 阈值上色），
 * 但代码从零手写：直接搬它的 shader 属于复制受版权保护的代码，不能进商业站；
 * 技术本身不受保护，所以这里用原生 WebGL2 写等价实现，依旧零依赖、全自托管。
 *
 * 三层结构（与参考站一致）：
 *   1. 流体：fbm + 域扭曲，时间只做垂直漂移 → 云持续流动；
 *   2. 像素化：屏幕切成 (PIX+GAP) 网格，每块的采样点被流场推开后取流体值；
 *   3. 上色：亮度 > (阈值 − 每块随机抖动) 才显示，颜色按每块随机数在白/青间选；
 *      鼠标只做颜色交换，不影响流动、不产生位移（参考站源码注释也是这么定的）。
 *
 * 之前的 Canvas 2D 版为什么被换掉：CPU 每帧只能采样预烘焙的粗网格，
 * 细节密度和流动感与 GPU 每像素现算差一个量级，这不是调参能追平的。
 * WebGL2 不可用时降级为静态 Canvas 2D 云，保证背景不空白。
 */

// ---------------------------- 可调参数 ----------------------------

const SPEED = 0.15 // 流体演化速度
const DENSITY = 1.5 // 噪声密度（越大云块越小越碎）
const FREQUENCY = 2.5 // 域扭曲强度（越大浓核与空洞越夸张）
const FLOW_AMP = 35 // 像素块采样点被流场推开的幅度（像素）
const PIX = 4 // 方块边长（CSS 像素）
const GAP = 2 // 方块间隙（CSS 像素）
const THRESHOLD = 0.5 // 亮度阈值：低于它不出点
const THR_JITTER = 0.08 // 每块阈值随机抖动，制造噪点边缘与空洞
const ACCENT_RATIO = 0.45 // 强调色（青）占比，其余为白
const MOUSE_R = 0.18 // 鼠标影响半径（UV）
const MOUSE_K = 1.0 // 鼠标变色强度

// 空间分布：点阵铺在 hero 区块内（画布由父容器限定，见 app.vue 的 .hero-bg），
// 密度自顶向下递减。对齐参考站实测的自顶 12 段密度
// （71,100,94,72,57,51,48,41,30,32,30,41 → 顶部满、底部约 3~4 成），
// 不是"顶部一小条、下面归零"。实现方式见 fragment 里的说明：抬阈值，不是压密度。
const THR_LIFT = 0.05 // 底部的阈值抬升量（越大越稀）

// 颜色与 main.css 的设计变量一致
const COL_ACCENT = [110 / 255, 231 / 255, 255 / 255] // --cyan
const COL_WHITE = [1.0, 1.0, 1.0]
const COL_BG = [6 / 255, 7 / 255, 13 / 255] // --bg

const DPR_MAX = 2

// ---------------------------- 着色器 ----------------------------

const VERT = `#version 300 es
layout(location = 0) in vec2 aPos;
void main() { gl_Position = vec4(aPos, 0.0, 1.0); }`

// 常量直接注入源码，让编译器折叠；uniform 只留每帧要变的
const FRAG = `#version 300 es
precision highp float;
out vec4 fragColor;

uniform vec2 uRes;    // CSS 像素
uniform float uDpr;
uniform float uTime;
uniform vec2 uMouse;  // UV 坐标，y 向上

#define SPEED ${SPEED.toFixed(4)}
#define DENSITY ${DENSITY.toFixed(4)}
#define FREQUENCY ${FREQUENCY.toFixed(4)}
#define FLOW_AMP ${FLOW_AMP.toFixed(1)}
#define PIX ${PIX.toFixed(1)}
#define GAP ${GAP.toFixed(1)}
#define THRESHOLD ${THRESHOLD.toFixed(4)}
#define THR_JITTER ${THR_JITTER.toFixed(4)}
#define ACCENT_RATIO ${ACCENT_RATIO.toFixed(4)}
#define MOUSE_R ${MOUSE_R.toFixed(4)}
#define MOUSE_K ${MOUSE_K.toFixed(2)}
#define THR_LIFT ${THR_LIFT.toFixed(4)}
#define COL_ACCENT vec3(${COL_ACCENT.map((v) => v.toFixed(4)).join(',')})
#define COL_WHITE vec3(${COL_WHITE.map((v) => v.toFixed(4)).join(',')})
#define COL_BG vec3(${COL_BG.map((v) => v.toFixed(5)).join(',')})

// ---- 值噪声 fbm（自写，IQ 风格 hash）----
float vhash(vec2 p) {
  return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453);
}

float vnoise(vec2 p) {
  vec2 i = floor(p);
  vec2 f = fract(p);
  vec2 u = f * f * (3.0 - 2.0 * f);
  float a = vhash(i);
  float b = vhash(i + vec2(1.0, 0.0));
  float c = vhash(i + vec2(0.0, 1.0));
  float d = vhash(i + vec2(1.0, 1.0));
  return mix(mix(a, b, u.x), mix(c, d, u.x), u.y);
}

float fbm(vec2 p) {
  float v = 0.0;
  float a = 0.5;
  for (int i = 0; i < 4; i++) {
    v += a * vnoise(p);
    p *= 2.03;
    a *= 0.5;
  }
  return v;
}

void main() {
  float dpr = uDpr;
  vec2 fragPx = gl_FragCoord.xy;
  vec2 uv = fragPx / (uRes * dpr);
  vec2 pixelCoord = fragPx / dpr; // CSS 像素

  // ---- 像素化网格 ----
  float total = PIX + GAP;
  vec2 blockId = floor(pixelCoord / total);
  vec2 inBlock = pixelCoord - blockId * total;
  vec2 blockUV = (blockId * total + 0.5 * PIX) / uRes;

  // ---- 流体：域扭曲 fbm，时间只做垂直漂移 ----
  float t = uTime * SPEED;
  vec2 q = vec2(
    fbm(blockUV * DENSITY + vec2(0.0, 0.22 * t)),
    fbm(blockUV * DENSITY + vec2(1.2, -0.31 * t))
  );

  // ---- 采样点被流场推开（流动感的来源）----
  vec2 flow = vec2(q.x * 0.3, q.y * 0.7) * 2.0;
  flow.x *= 0.5; // 弱化横向
  flow.y *= 1.5; // 强化纵向
  vec2 suv = blockUV - flow * (FLOW_AMP / uRes);
  float val = fbm(suv * DENSITY + q * FREQUENCY);

  // ---- 阈值 + 每块随机抖动 ----
  float rnd = vhash(blockId + 7.0);
  float thr = THRESHOLD - THR_JITTER * rnd;

  // ---- 空间分布：hero 内自顶向下递减 ----
  // 这里必须用"抬阈值"，不能乘一个小于 1 的系数去压 val：
  // val 本来就贴着阈值分布，一乘就直接掉到阈值以下，点会整片消失、分布断掉。
  // 抬阈值是渐进的 —— 越往下越少的点能达到，才是实测那种 100→~35% 的平滑递减。
  float yTop = 1.0 - uv.y; // 自顶向下 0→1（uv.y 自底向上）
  thr += smoothstep(0.0, 0.95, yTop) * THR_LIFT;

  vec3 col = COL_BG;
  if (val > thr) {
    // 鼠标：只做颜色交换
    float m = (1.0 - smoothstep(0.0, MOUSE_R, distance(uv, uMouse))) * MOUSE_K;
    col = rnd < ACCENT_RATIO ? mix(COL_ACCENT, COL_WHITE, m) : mix(COL_WHITE, COL_ACCENT, m);
  }

  // ---- 方块间隙 ----
  if (inBlock.x > PIX || inBlock.y > PIX) col = COL_BG;

  fragColor = vec4(col, 1.0);
}`

// ---------------------------- 状态 ----------------------------

const canvasRef = ref(null)

let gl = null
let raf = 0
let startedAt = 0
let uRes = null
let uDpr = null
let uTime = null
let uMouse = null

const pointer = {
  tx: -10,
  ty: -10,
  x: -10,
  y: -10,
}

// ---------------------------- WebGL ----------------------------

function compile(type, src) {
  const s = gl.createShader(type)
  gl.shaderSource(s, src)
  gl.compileShader(s)
  if (!gl.getShaderParameter(s, gl.COMPILE_STATUS)) {
    console.error('[DotMatrix] shader 编译失败:', gl.getShaderInfoLog(s))
    return null
  }
  return s
}

function initGL() {
  const el = canvasRef.value
  gl = el.getContext('webgl2', { antialias: false, alpha: false })
  if (!gl) return false

  const vs = compile(gl.VERTEX_SHADER, VERT)
  const fs = compile(gl.FRAGMENT_SHADER, FRAG)
  if (!vs || !fs) return false

  const prog = gl.createProgram()
  gl.attachShader(prog, vs)
  gl.attachShader(prog, fs)
  gl.linkProgram(prog)
  if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) {
    console.error('[DotMatrix] program 链接失败:', gl.getProgramInfoLog(prog))
    return false
  }
  gl.useProgram(prog)

  // 全屏三角形，省一次对角线拆分
  const buf = gl.createBuffer()
  gl.bindBuffer(gl.ARRAY_BUFFER, buf)
  gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW)
  gl.enableVertexAttribArray(0)
  gl.vertexAttribPointer(0, 2, gl.FLOAT, false, 0, 0)

  uRes = gl.getUniformLocation(prog, 'uRes')
  uDpr = gl.getUniformLocation(prog, 'uDpr')
  uTime = gl.getUniformLocation(prog, 'uTime')
  uMouse = gl.getUniformLocation(prog, 'uMouse')
  return true
}

function resize() {
  const el = canvasRef.value
  if (!el || !gl) return
  const dpr = Math.min(window.devicePixelRatio || 1, DPR_MAX)
  const w = el.clientWidth
  const h = el.clientHeight
  el.width = Math.round(w * dpr)
  el.height = Math.round(h * dpr)
  gl.viewport(0, 0, el.width, el.height)
  gl.uniform2f(uRes, w, h)
  gl.uniform1f(uDpr, dpr)
}

function drawFrame(t) {
  pointer.x += (pointer.tx - pointer.x) * 0.12
  pointer.y += (pointer.ty - pointer.y) * 0.12
  gl.uniform1f(uTime, t)
  gl.uniform2f(uMouse, pointer.x, pointer.y)
  gl.drawArrays(gl.TRIANGLES, 0, 3)
}

function frame(now) {
  raf = requestAnimationFrame(frame)
  drawFrame((now - startedAt) * 0.001)
}

// ---------------------------- 降级：静态 Canvas 2D 云 ----------------------------

function fbmJs(x, y) {
  const h2 = (ix, iy) => {
    let n = Math.imul(ix, 374761393) + Math.imul(iy, 668265263)
    n = Math.imul(n ^ (n >>> 13), 1274126177)
    n ^= n >>> 16
    return (n >>> 0) / 4294967296
  }
  const n2 = (px, py) => {
    const ix = Math.floor(px)
    const iy = Math.floor(py)
    const fx = px - ix
    const fy = py - iy
    const ux = fx * fx * (3 - 2 * fx)
    const uy = fy * fy * (3 - 2 * fy)
    const a = h2(ix, iy)
    const b = h2(ix + 1, iy)
    const c = h2(ix, iy + 1)
    const d = h2(ix + 1, iy + 1)
    return a + (b - a) * ux + (c - a) * uy + (a - b - c + d) * ux * uy
  }
  return n2(x, y) * 0.5 + n2(x * 2.03, y * 2.03) * 0.25 + n2(x * 4.06, y * 4.06) * 0.125
}

function fallbackStatic() {
  const el = canvasRef.value
  const ctx = el.getContext('2d')
  const dpr = Math.min(window.devicePixelRatio || 1, DPR_MAX)
  const w = el.clientWidth
  const h = el.clientHeight
  el.width = Math.round(w * dpr)
  el.height = Math.round(h * dpr)
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
  ctx.fillStyle = `rgb(${COL_BG.map((v) => Math.round(v * 255)).join(',')})`
  ctx.fillRect(0, 0, w, h)

  const step = PIX + GAP
  for (let y = 0; y < h; y += step) {
    for (let x = 0; x < w; x += step) {
      const v = fbmJs((x / w) * DENSITY + 3, (y / h) * DENSITY + 8)
      if (v <= THRESHOLD) continue
      const rnd = fbmJs(x * 0.13, y * 0.17)
      const c = rnd < ACCENT_RATIO ? COL_ACCENT : COL_WHITE
      ctx.fillStyle = `rgb(${c.map((k) => Math.round(k * 255)).join(',')})`
      ctx.fillRect(x, y, PIX, PIX)
    }
  }
  console.info('[DotMatrix] WebGL2 不可用，已降级为静态点阵。')
}

// ---------------------------- 事件 ----------------------------

function onPointerMove(e) {
  const el = canvasRef.value
  if (!el) return
  // 画布不再是全屏固定层，必须按它自身的视口位置换算 UV
  const r = el.getBoundingClientRect()
  pointer.tx = (e.clientX - r.left) / r.width
  pointer.ty = 1 - (e.clientY - r.top) / r.height
}

function onPointerLeave() {
  pointer.tx = -10
  pointer.ty = -10
}

let resizeTimer = 0
function onResize() {
  clearTimeout(resizeTimer)
  resizeTimer = setTimeout(() => {
    if (!gl) return
    resize()
    drawFrame((performance.now() - startedAt) * 0.001)
  }, 160)
}

function onVisibility() {
  if (document.hidden) {
    cancelAnimationFrame(raf)
    raf = 0
  } else if (!raf && gl) {
    raf = requestAnimationFrame(frame)
  }
}

// ---------------------------- 生命周期 ----------------------------

onMounted(() => {
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches

  if (!initGL()) {
    // canvas 一旦绑了 webgl2 就再也拿不到 2d 上下文，所以这里必须分支：
    // 有 gl 就退化为纯色清屏（至少不崩、不空白），连 gl 都没有才走静态点阵
    if (gl) {
      gl.clearColor(COL_BG[0], COL_BG[1], COL_BG[2], 1)
      gl.clear(gl.COLOR_BUFFER_BIT)
      console.info('[DotMatrix] WebGL2 初始化失败，降级为纯色背景。')
    } else {
      fallbackStatic()
    }
    return
  }

  resize()

  if (reduced) {
    console.info('[DotMatrix] 检测到 prefers-reduced-motion，背景只渲染静态一帧。')
    drawFrame(0)
    return
  }

  startedAt = performance.now()
  raf = requestAnimationFrame(frame)
  window.addEventListener('pointermove', onPointerMove, { passive: true })
  document.addEventListener('pointerleave', onPointerLeave)
  window.addEventListener('resize', onResize)
  document.addEventListener('visibilitychange', onVisibility)
})

onBeforeUnmount(() => {
  clearTimeout(resizeTimer)
  cancelAnimationFrame(raf)
  window.removeEventListener('pointermove', onPointerMove)
  document.removeEventListener('pointerleave', onPointerLeave)
  window.removeEventListener('resize', onResize)
  document.removeEventListener('visibilitychange', onVisibility)
})
</script>

<template>
  <canvas ref="canvasRef" class="dotmatrix" aria-hidden="true" />
</template>

<style scoped>
.dotmatrix {
  position: absolute;
  inset: 0;
  z-index: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
}
</style>
