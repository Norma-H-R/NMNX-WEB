<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * 点阵云团背景 —— WebGL2 版。
 *
 * 结构完全对齐参考站（其 shader 已通过 CDP 钩 shaderSource 完整取回并逐段比对），
 * 但代码是从零手写的：直接逐字复制它的 shader 属于复制受版权保护的代码，
 * 不能进商业站；而技术机制不受保护。配色换成本站的青/白。
 *
 * 三层（与参考站一致）：
 *   1. 流体：fbm + 域扭曲，两路扭曲量朝相反方向漂移 → 流体自己在变形；
 *   2. 像素化：屏幕切成 (PIX+GAP) 网格，**每个块的采样点被本地流场推开**
 *      （逐块方向不同 → 云像液体各处流向不同方向，而不是整片同向平移）；
 *   3. 判定：亮度 > 阈值 − 极小的每块随机量 → 出点，颜色按噪声在白/青间选。
 *
 * 两次踩坑（都已定位，别再犯）：
 *   a) 对最终密度场做**整体平移**：整片云同向滑、形状不变 → 看着就是"统一运动 + 固定图形"。
 *      参考站没有这种平移，流动来自"采样点被本地流场推开"。
 *   b) 二值化用**全幅随机抖动**（每块独立 0~1 再比概率）：图案会由抖动主导、脱离流体，
 *      变成"一颗颗原地闪"而不是粒子云。参考站的抖动只有 0.08，极小 ——
 *      这样画面基本等于"流体等值线在动"，图案必然跟着流体走。
 */

// ---------------------------- 可调参数 ----------------------------

const SPEED = 0.15 // 流体演化速度（时间只做漂移）
const DENSITY = 1.5 // 噪声密度（越大越细碎）
const FREQUENCY = 2.5 // 域扭曲强度
const PIX = 6 // 方块边长（CSS 像素）
const GAP = 2 // 方块间隙（CSS 像素）

// 采样点被本地流场推开的幅度（CSS 像素）。这是"流动感"的来源：
// 每个块的采样点朝它自己的流向偏移，于是云像液体一样各处流向不同方向。
const FLOW_AMP = 35

// 阈值 + 每块随机抖动。
// ⚠️ 抖动必须**极小**（参考站是 0.08）。抖动一大，图案就由抖动主导、脱离流体，
// 变成"一颗颗原地闪"，看不出流动 —— 这正是之前一路做偏的根因。
// 阈值按**实测分布**标定（不是拍脑袋）：本实现的流体亮度均值约 0.606、标准差约 0.147。
// 注意抖动会整体把有效阈值拉低约 JITTER/2，标定时要把这半个抖动算进去
// （分布很陡，不算的话覆盖率会大出一倍多）。
const THRESHOLD = 0.81
const JITTER = 0.08

const ACCENT_RATIO = 0.32 // 强调色（青）占比，其余为白
// 颜色簇：用低尺度噪声 + 缓慢漂移决定白/青，使变色以零散小簇出现，而不是整片刷。
const COLOR_SCALE = 10
const COLOR_RATE = 0.12

const MOUSE_R = 0.18 // 鼠标影响半径（UV）
const MOUSE_K = 1.0 // 鼠标变色强度

// 颜色与 main.css 的设计变量一致
const COL_ACCENT = [110 / 255, 231 / 255, 255 / 255] // --cyan
const COL_WHITE = [1.0, 1.0, 1.0]
const COL_BG = [6 / 255, 7 / 255, 13 / 255] // --bg

// 每帧要算 3 次 fbm（2 次给流场 + 1 次给流体），再叠 DPR 会明显变贵；
// 而输出本来就是 6px 方块，高 DPR 对观感没有收益。
const DPR_MAX = 1.5

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
#define PIX ${PIX.toFixed(1)}
#define GAP ${GAP.toFixed(1)}
#define FLOW_AMP ${FLOW_AMP.toFixed(1)}
#define THRESHOLD ${THRESHOLD.toFixed(4)}
#define JITTER ${JITTER.toFixed(4)}
#define ACCENT_RATIO ${ACCENT_RATIO.toFixed(4)}
#define COLOR_SCALE ${COLOR_SCALE.toFixed(4)}
#define COLOR_RATE ${COLOR_RATE.toFixed(4)}
#define MOUSE_R ${MOUSE_R.toFixed(4)}
#define MOUSE_K ${MOUSE_K.toFixed(2)}
#define COL_ACCENT vec3(${COL_ACCENT.map((v) => v.toFixed(4)).join(',')})
#define COL_WHITE vec3(${COL_WHITE.map((v) => v.toFixed(4)).join(',')})
#define COL_BG vec3(${COL_BG.map((v) => v.toFixed(5)).join(',')})

// 哈希刻意不用 fract(sin(dot(p, 大常数)))：sin 参数一大 float 精度就崩，
// 哈希会退化成带空间条带的伪随机，云会莫名聚在一侧。这里只靠乘加，全精度都稳。
float vhash(vec2 p) {
  vec3 p3 = fract(vec3(p.xyx) * 0.1031);
  p3 += dot(p3, p3.yzx + 33.33);
  return fract((p3.x + p3.y) * p3.z);
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
  return 0.5 * vnoise(p) + 0.3 * vnoise(p * 2.03) + 0.2 * vnoise(p * 4.09);
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

  // 各向同性映射：uv 两轴都是 0~1，而画布宽高比约 1.76，
  // 不校正的话噪声会被横向拉长、云挤到某一侧。
  float asp = uRes.x / uRes.y;
  vec2 p = vec2(blockUV.x * asp, blockUV.y) * DENSITY;

  // ---- 流体：域扭曲 fbm，两路扭曲量朝相反方向漂移 ----
  // 相反漂移很关键：单向漂移会让整片云同向平移，这里云是在原地不断变形。
  float t = uTime * SPEED;
  vec2 q = vec2(
    fbm(p + vec2(0.0, 0.22 * t)),
    fbm(p + vec2(1.2, -0.31 * t))
  );

  // ---- 采样点被本地流场推开：流动感的真正来源 ----
  vec2 flow = vec2(q.x * 0.3, q.y * 0.7) * 2.0;
  flow.x *= 0.5; // 弱化横向
  flow.y *= 1.5; // 强化纵向
  // 把像素位移换算成噪声坐标：y 方向 1 个噪声单位 = uRes.y / DENSITY 像素
  vec2 sp = p - flow * (FLOW_AMP * DENSITY / uRes.y);

  // 在被推开的采样点上取流体亮度
  float bright = fbm(sp + q * FREQUENCY);

  // ---- 阈值 + 极小的每块随机量 ----
  float rnd = vhash(blockId + 7.0);
  float thr = THRESHOLD - JITTER * rnd;

  vec3 col = COL_BG;
  if (bright > thr) {
    // 颜色：低尺度噪声 + 缓慢漂移 → 变色成零散小簇（十几~几十个），不整片刷。
    // 采在 sp 上，所以颜色簇也会跟着流体一起流动。
    float cfield = vnoise(sp * COLOR_SCALE + vec2(0.0, uTime * COLOR_RATE));
    col = cfield > (1.0 - ACCENT_RATIO) ? COL_ACCENT : COL_WHITE;

    // 鼠标：换成同一种颜色（不做圆形渐变），形状与深浅随机
    float d = distance(uv, uMouse);
    if (d < MOUSE_R) {
      float pickShape = vhash(blockId + 31.0);
      float pickDepth = vhash(blockId + 53.0);
      if (pickShape < (1.0 - d / MOUSE_R) * MOUSE_K) {
        col = COL_ACCENT * (0.45 + 0.55 * pickDepth);
      }
    }
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
  pointer.x += (pointer.tx - pointer.x) * 0.3
  pointer.y += (pointer.ty - pointer.y) * 0.3
  gl.uniform1f(uTime, t)
  gl.uniform2f(uMouse, pointer.x, pointer.y)
  gl.drawArrays(gl.TRIANGLES, 0, 3)
}

function frame(now) {
  raf = requestAnimationFrame(frame)
  drawFrame((now - startedAt) * 0.001)
}

// ---------------------------- 降级：静态 Canvas 2D 点阵 ----------------------------

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
  return n2(x, y) * 0.5 + n2(x * 2.03, y * 2.03) * 0.3 + n2(x * 4.06, y * 4.06) * 0.2
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
  const asp = w / h
  for (let y = 0; y < h; y += step) {
    for (let x = 0; x < w; x += step) {
      const v = fbmJs((x / w) * asp * DENSITY, (y / h) * DENSITY)
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
  top: 0;
  left: 0;
  z-index: 0;
  width: 100%;
  /* 与第一屏同高。用 svh 而不是 vh，移动端地址栏收起/展开时不会跳。
     这里刻意不用 inset:0 —— 那会跟着 hero 的内容高度走，
     hero 内容一长点阵就被拉高，就不是"第一屏"了。 */
  height: 100svh;
  pointer-events: none;
}
</style>
