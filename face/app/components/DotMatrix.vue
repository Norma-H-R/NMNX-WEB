<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * 点阵云团背景 —— **照搬参考站的两趟 GPU 管线**，原生 WebGL2 手写。
 *
 * 参考站的 shader 原文已通过 CDP 钩 shaderSource 完整抓取并存档在 _ref/trae-glsl.txt
 * （仅本地对照，被 gitignore，不入构建）。这里是按它的管线结构复刻的实现：
 *
 *   趟 1  FluidShader        → 渲染到 framebuffer 贴图（流体色场，只当密度信号用）
 *   趟 2  PixelationEffect   → 采样那张贴图：网格分块 → 采样点被本地流场推开
 *                              → 块亮度 > 阈值 − 0.08×每块随机 → 按随机数上色
 *
 * 与原版的一处必要差异（**配色**）：
 *   原版判定量是流体颜色的平均亮度，它用 绿(均值0.56) + 白(1.0) → 亮度跨度 0.44；
 *   我们若用站点的 青(均值0.78) + 白(1.0)，跨度只剩 0.22，0.08 的阈值抖动会直接
 *   淹没判定（图案由抖动主导 → 变成一颗颗原地闪）。
 *   所以流体层改成混「深蓝 → 白」（跨度 0.78），粒子颜色仍按站点 白/青 输出。
 *   流体本身不可见，只当密度信号。
 */

// ---------------------------- 可调参数 ----------------------------

// 以下数值来自对参考站运行时的实测抓取（_ref/trae-uniforms.json），不是估的
const PIX = 5 // 方块边长：原版 e1PixelSize = 5
const GAP = 2 // 方块间隙：原版 e1PixelGap = 2

// 流体层两个颜色（只用于"亮度"这个密度信号，不出现在画面上）
// 原版是 绿(#32F08C, 均值 0.562) → 白(1.0)，跨度 0.44；我们用 深蓝 → 白，跨度 0.78，
// 判定分辨力更高（原因见文件头说明）。
const FLUID_C1 = [0.06, 0.15, 0.45] // 深蓝
const FLUID_C2 = [1.0, 1.0, 1.0] // 白

// 流体层 uniform（原版实测：uSpeed=0.18 / uDensity=0.5 / uFrequency=4）
// 注意：像素化那趟里算流场用的是**另一组硬编码值**(0.15/1.5/2.5)，原版就是分开的。
const SPEED = 0.18
const DENSITY = 0.5
const FREQUENCY = 4

// 像素化参数
const FLOW_AMP = 35 // 采样点被流场推开的幅度（像素）：原版 ×35.0
// 亮度阈值：原版 e1Threshold = 0.87（作用在它自己的配色跨度上）。
// 因为我们的流体参数/配色与它不同，这里按**本实现实测分布**标定：
// 均值 0.601、标准差 0.0766，取 p85 ≈ 0.702 → 覆盖率约 15%（已含 JITTER/2 的下移）。
// 改流体参数或配色后必须重新标。
const THRESHOLD = 0.705
const JITTER = 0.08 // 每块阈值随机量：原版就是 0.08，必须小
// 强调色（蓝）占比。原版 e1GreenRatio = 0.49，这里按"蓝色再少 35%"往下调：
// 0.49 × 0.65 ≈ 0.3185。蓝白走的是同一个 randCol 与同一个阈值，所以这就是纯粹的
// 数量比例，调它不会让蓝点变暗、也不会改变疏密。
const ACCENT_RATIO = 0.3185

// 蓝/白颜色随时间重掷的速率（每格相位错开，不是整屏同步闪）。
// 0 = 颜色只跟着云流、永不重掷；调大 = 蓝白换得更勤。
// 1.0 时节奏已接近白色点自身的出现/消失（实测 1.5s 内约 62% vs 88%）。
// 别超过 ~2：再快眼睛会把蓝白平均成灰。
const COLOR_RATE = 1.0

// 鼠标：原版 e1UMouseRadius = 0.3 / e1UMouseStrength = 1.3
// （它是按距离平滑的 → 会形成一圈圆形渐变；先照搬，之后要删改的就是这里）
const MOUSE_R = 0.3
const MOUSE_K = 1.3

// 粒子颜色 = 站点配色。
// DOT_DIM 是整体压暗系数（1 = 原亮度，0.55 = 压暗 45%）。
// ⚠️ 它只压"画面上的粒子颜色"：判定用的亮度来自流体贴图（FLUID_C1/C2），
// 两者独立，所以调这个不会改变点阵的疏密，放心调。
const DOT_DIM = 0.55
const COL_ACCENT = [110 / 255, 231 / 255, 255 / 255].map((v) => v * DOT_DIM) // --cyan
const COL_WHITE = [1.0, 1.0, 1.0].map((v) => v * DOT_DIM)
const COL_BG = [6 / 255, 7 / 255, 13 / 255] // --bg

const DPR_MAX = 1.5 // 输出本就是 6px 方块，高 DPR 无收益
const FLUID_SCALE = 0.5 // 流体那趟降半分辨率渲染（流体很平滑，肉眼无差，省 4 倍）

// ---------------------------- 着色器 ----------------------------

const VERT = `#version 300 es
layout(location = 0) in vec2 aPos;
void main() { gl_Position = vec4(aPos, 0.0, 1.0); }`

// ---- 趟 1：流体 ----
const FLUID_FRAG = `#version 300 es
precision highp float;
out vec4 fragColor;

uniform vec2 uRes;
uniform float uTime;
uniform float uSpeed;
uniform float uDensity;
uniform float uFrequency;
uniform vec3 uColor1;
uniform vec3 uColor2;

vec2 hash( vec2 p ) {
  p = vec2(dot(p,vec2(127.1,311.7)), dot(p,vec2(269.5,183.3)));
  return -1.0 + 2.0 * fract(sin(p) * 43758.5453123);
}

float noise(vec2 p) {
  const float K1 = 0.366025404;
  const float K2 = 0.211324865;
  vec2 i = floor(p + (p.x + p.y) * K1);
  vec2 a = p - i + (i.x + i.y) * K2;
  vec2 o = (a.x > a.y) ? vec2(1.0, 0.0) : vec2(0.0, 1.0);
  vec2 b = a - o + K2;
  vec2 c = a - 1.0 + 2.0 * K2;
  vec3 h = max(0.5 - vec3(dot(a,a), dot(b,b), dot(c,c)), 0.0);
  vec3 n = h*h*h*h * vec3(dot(a, hash(i+0.0)), dot(b, hash(i+o)), dot(c, hash(i+1.0)));
  return dot(n, vec3(70.0));
}

float fbm(vec2 p) {
  float value = 0.0;
  float amplitude = 0.5;
  float frequency = 1.0;
  for (int i = 0; i < 3; i++) {
    value += amplitude * noise(p * frequency);
    frequency *= 2.0;
    amplitude *= 0.5;
  }
  return value * 0.5 + 0.5;
}

void main() {
  vec2 st = gl_FragCoord.xy / uRes;
  float t = uTime * uSpeed;
  vec2 q = vec2(
    fbm(st * uDensity + vec2(0.0, 0.2 * t)),
    fbm(st * uDensity + vec2(1.2, -0.3 * t))
  );
  float n = fbm(st * uDensity + q * uFrequency);
  vec3 color = mix(uColor1, uColor2, n);
  fragColor = vec4(color, 1.0);
}`

// ---- 趟 2：像素化 ----
const PIXEL_FRAG = `#version 300 es
precision highp float;
out vec4 fragColor;

uniform sampler2D uFluid;
uniform vec2 uRes;
uniform float uTime;
uniform vec2 uMouse;
uniform float uMouseRadius;
uniform float uMouseStrength;

#define PIX ${PIX.toFixed(1)}
#define GAP ${GAP.toFixed(1)}
#define FLOW_AMP ${FLOW_AMP.toFixed(1)}
#define THRESHOLD ${THRESHOLD.toFixed(4)}
#define JITTER ${JITTER.toFixed(4)}
#define ACCENT_RATIO ${ACCENT_RATIO.toFixed(4)}
#define COLOR_RATE ${COLOR_RATE.toFixed(4)}
#define MOUSE_R ${MOUSE_R.toFixed(4)}
#define MOUSE_K ${MOUSE_K.toFixed(2)}
#define COL_ACCENT vec3(${COL_ACCENT.map((v) => v.toFixed(4)).join(',')})
#define COL_WHITE vec3(${COL_WHITE.map((v) => v.toFixed(4)).join(',')})
#define COL_BG vec3(${COL_BG.map((v) => v.toFixed(5)).join(',')})

float e1Random(vec2 st) {
  return fract(sin(dot(st.xy, vec2(12.9898, 78.233))) * 43758.5453123);
}

vec2 e1Hash(vec2 p) {
  p = vec2(dot(p,vec2(127.1,311.7)), dot(p,vec2(269.5,183.3)));
  return -1.0 + 2.0 * fract(sin(p) * 43758.5453123);
}

float e1Noise(vec2 p) {
  const float K1 = 0.366025404;
  const float K2 = 0.211324865;
  vec2 i = floor(p + (p.x + p.y) * K1);
  vec2 a = p - i + (i.x + i.y) * K2;
  vec2 o = (a.x > a.y) ? vec2(1.0, 0.0) : vec2(0.0, 1.0);
  vec2 b = a - o + K2;
  vec2 c = a - 1.0 + 2.0 * K2;
  vec3 h = max(0.5 - vec3(dot(a,a), dot(b,b), dot(c,c)), 0.0);
  vec3 n = h*h*h*h * vec3(dot(a, e1Hash(i+0.0)), dot(b, e1Hash(i+o)), dot(c, e1Hash(i+1.0)));
  return dot(n, vec3(70.0));
}

float e1Fbm(vec2 p) {
  float value = 0.0;
  float amplitude = 0.5;
  float frequency = 1.0;
  for (int i = 0; i < 3; i++) {
    value += amplitude * e1Noise(p * frequency);
    frequency *= 2.0;
    amplitude *= 0.5;
  }
  return value * 0.5 + 0.5;
}

vec2 e1ComputeFluidFlow(vec2 uv, float time, float speed, float density, float frequency) {
  vec2 q = vec2(
    e1Fbm(uv * density + vec2(0.0, 0.2 * time * speed)),
    e1Fbm(uv * density + vec2(1.2, -0.3 * time * speed))
  );
  vec2 flowVector = vec2(q.x * 0.3, q.y * 0.7) * 2.0;
  flowVector.x *= 0.5;
  flowVector.y *= 1.5;
  return flowVector;
}

void main() {
  vec2 uv = gl_FragCoord.xy / uRes;

  float time = uTime;
  float speed = 0.15;
  float density = 1.5;
  float frequency = 2.5;

  vec2 pixelCoord = uv * uRes;
  float totalSize = PIX + GAP;
  vec2 blockId = floor(pixelCoord / totalSize);
  vec2 blockCenterUV = (blockId * totalSize + vec2(PIX / 2.0)) / uRes;

  vec2 flow = e1ComputeFluidFlow(blockCenterUV, time, speed, density, frequency) * FLOW_AMP;

  vec2 blockPos = blockId * totalSize;
  vec2 posInBlock = pixelCoord - blockPos;
  vec2 offsetBlockPos = blockPos - flow;

  // 间隙：先判再采样
  if (posInBlock.x > PIX || posInBlock.y > PIX) {
    fragColor = vec4(COL_BG, 1.0);
    return;
  }

  vec2 offsetBlockCenter = offsetBlockPos + vec2(PIX / 2.0);
  vec2 blockUv = offsetBlockCenter / uRes;
  vec4 color = texture(uFluid, blockUv);
  float brightness = (color.r + color.g + color.b) / 3.0;

  // 粒子身份 = **流动之后**的格位，而不是固定的屏幕格 blockId。
  // 原版这里是 e1Random(blockId)：种子绑在屏幕网格上，蓝/白于是成了**一层永远
  // 不动的固定网纹** —— 白云从上面流过时就成了"白色里透出一层固定的蓝"，看着
  // 就是蓝色是死的、只有白色在动。改成跟流动绑定的身份后，整个蓝白图案会跟着
  // 云一起流，蓝点自然就和白点一样在动。
  vec2 cellId = floor(offsetBlockPos / totalSize);

  // 亮度抖动：只按粒子身份取 → 一个亮着的点在它流动过程中保持稳定，不会原地闪
  float randJit = e1Random(cellId);

  // 颜色：同一个粒子身份再拌一个"每格相位错开"的时间项 → 过一会儿还会重掷一次
  // 颜色（COLOR_RATE=0 就纯随流、永不重掷）。蓝和白都是这样随机出现的，没有主次，
  // 更不是"先有白、白里再挑出蓝"。
  float rt = time * COLOR_RATE + e1Random(cellId + 0.5) * 37.0;
  float rc0 = e1Random(cellId + vec2(floor(rt), 0.0));
  float rc1 = e1Random(cellId + vec2(floor(rt) + 1.0, 0.0));
  // 硬切换，不做插值：rc0/rc1 本身是均匀分布，直接切换后取值依然均匀，
  // ACCENT_RATIO 才能线性对应到实际蓝点占比。
  // （用 mix 插值会把分布压向 0.5 —— 实测 ACCENT_RATIO=0.3185 只出 25.6% 蓝点，
  //  比设定少了 6 个百分点。换色节奏不受影响，仍然是每格约 1/COLOR_RATE 秒一次。）
  float randCol = fract(rt) < 0.5 ? rc0 : rc1;

  float dynamicThreshold = THRESHOLD - JITTER * randJit;

  float dist = distance(blockCenterUV, uMouse);
  float mouseFactor = (1.0 - smoothstep(0.0, MOUSE_R, dist)) * MOUSE_K;

  vec3 col = COL_BG;
  if (brightness > dynamicThreshold) {
    if (randCol < ACCENT_RATIO) {
      vec3 c = COL_ACCENT;
      if (mouseFactor > 0.0) c = mix(c, COL_WHITE, mouseFactor);
      col = c;
    } else {
      if (mouseFactor > 0.0) col = mix(COL_WHITE, COL_ACCENT, mouseFactor);
      else col = COL_WHITE;
    }
  }
  fragColor = vec4(col, 1.0);
}`

// ---------------------------- 状态 ----------------------------

const canvasRef = ref(null)

let gl = null
let raf = 0
let startedAt = 0

let fluidProg = null
let pixelProg = null
let fluidU = {}
let pixelU = {}
let quad = null

// framebuffer（流体那趟的渲染目标）
let fbo = null
let fboTex = null
let fboW = 0
let fboH = 0

const pointer = { tx: -10, ty: -10, x: -10, y: -10 }

// ---------------------------- WebGL ----------------------------

function compile(type, src, label) {
  const s = gl.createShader(type)
  gl.shaderSource(s, src)
  gl.compileShader(s)
  if (!gl.getShaderParameter(s, gl.COMPILE_STATUS)) {
    console.error(`[DotMatrix] ${label} shader 编译失败:`, gl.getShaderInfoLog(s))
    return null
  }
  return s
}

function link(vsSrc, fsSrc, label) {
  const vs = compile(gl.VERTEX_SHADER, vsSrc, label + '/vert')
  const fs = compile(gl.FRAGMENT_SHADER, fsSrc, label + '/frag')
  if (!vs || !fs) return null
  const p = gl.createProgram()
  gl.attachShader(p, vs)
  gl.attachShader(p, fs)
  gl.linkProgram(p)
  if (!gl.getProgramParameter(p, gl.LINK_STATUS)) {
    console.error(`[DotMatrix] ${label} program 链接失败:`, gl.getProgramInfoLog(p))
    return null
  }
  return p
}

function u(prog, names) {
  const out = {}
  for (const n of names) out[n] = gl.getUniformLocation(prog, n)
  return out
}

function initGL() {
  const el = canvasRef.value
  gl = el.getContext('webgl2', { antialias: false, alpha: false })
  if (!gl) return false

  fluidProg = link(VERT, FLUID_FRAG, 'fluid')
  pixelProg = link(VERT, PIXEL_FRAG, 'pixel')
  if (!fluidProg || !pixelProg) return false

  fluidU = u(fluidProg, ['uRes', 'uTime', 'uSpeed', 'uDensity', 'uFrequency', 'uColor1', 'uColor2'])
  pixelU = u(pixelProg, ['uFluid', 'uRes', 'uTime', 'uMouse', 'uMouseRadius', 'uMouseStrength'])

  quad = gl.createBuffer()
  gl.bindBuffer(gl.ARRAY_BUFFER, quad)
  gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW)
  gl.enableVertexAttribArray(0)
  gl.vertexAttribPointer(0, 2, gl.FLOAT, false, 0, 0)

  // 流体那趟的输出贴图 + framebuffer
  fboTex = gl.createTexture()
  gl.bindTexture(gl.TEXTURE_2D, fboTex)
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR)
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR)
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE)
  gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE)

  fbo = gl.createFramebuffer()
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

  fboW = Math.max(1, Math.round(el.width * FLUID_SCALE))
  fboH = Math.max(1, Math.round(el.height * FLUID_SCALE))

  gl.bindTexture(gl.TEXTURE_2D, fboTex)
  gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, fboW, fboH, 0, gl.RGBA, gl.UNSIGNED_BYTE, null)
  gl.bindFramebuffer(gl.FRAMEBUFFER, fbo)
  gl.framebufferTexture2D(gl.FRAMEBUFFER, gl.COLOR_ATTACHMENT0, gl.TEXTURE_2D, fboTex, 0)
  gl.bindFramebuffer(gl.FRAMEBUFFER, null)
}

function drawFrame(t) {
  const el = canvasRef.value
  pointer.x += (pointer.tx - pointer.x) * 0.2
  pointer.y += (pointer.ty - pointer.y) * 0.2

  // ---- 趟 1：流体 → FBO ----
  gl.bindFramebuffer(gl.FRAMEBUFFER, fbo)
  gl.viewport(0, 0, fboW, fboH)
  gl.useProgram(fluidProg)
  gl.uniform2f(fluidU.uRes, fboW, fboH)
  gl.uniform1f(fluidU.uTime, t)
  gl.uniform1f(fluidU.uSpeed, SPEED)
  gl.uniform1f(fluidU.uDensity, DENSITY)
  gl.uniform1f(fluidU.uFrequency, FREQUENCY)
  gl.uniform3f(fluidU.uColor1, FLUID_C1[0], FLUID_C1[1], FLUID_C1[2])
  gl.uniform3f(fluidU.uColor2, FLUID_C2[0], FLUID_C2[1], FLUID_C2[2])
  gl.drawArrays(gl.TRIANGLES, 0, 3)

  // ---- 趟 2：像素化 → 屏幕 ----
  gl.bindFramebuffer(gl.FRAMEBUFFER, null)
  gl.viewport(0, 0, el.width, el.height)
  gl.useProgram(pixelProg)
  gl.activeTexture(gl.TEXTURE0)
  gl.bindTexture(gl.TEXTURE_2D, fboTex)
  gl.uniform1i(pixelU.uFluid, 0)
  gl.uniform2f(pixelU.uRes, el.width, el.height)
  gl.uniform1f(pixelU.uTime, t)
  gl.uniform2f(pixelU.uMouse, pointer.x, pointer.y)
  gl.uniform1f(pixelU.uMouseRadius, MOUSE_R)
  gl.uniform1f(pixelU.uMouseStrength, MOUSE_K)
  gl.drawArrays(gl.TRIANGLES, 0, 3)
}

function frame(now) {
  raf = requestAnimationFrame(frame)
  drawFrame((now - startedAt) * 0.001)
}

// ---------------------------- 事件 ----------------------------

function onPointerMove(e) {
  const el = canvasRef.value
  if (!el) return
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

// 是否该在跑：同时受「标签页可见」和「画布在视口附近」两个条件约束。
//
// 为什么要按视口可见性停画：这条画布的开销正比于像素数（实测 0.5M 像素约 47fps、
// 4.7M 像素只有 8fps），而它默认连滚到下面几个区块时都不停 —— 纯属白烧。
//
// 停/恢复为什么不会有视觉断层：
//   1. 恢复用的是动画时间 = now - startedAt（绝对时间，见 frame()），不是累加帧数，
//      所以暂停期间进度照走，重新出现时直接就是当前时刻该有的那一帧，不会从头播；
//   2. 观察器带 200px rootMargin，等真正滚进视野时它早就在画了。
let onScreen = true
let tabVisible = true
let staticOnly = false

function syncLoop() {
  const want = !staticOnly && onScreen && tabVisible && !!gl
  if (want && !raf) {
    raf = requestAnimationFrame(frame)
  } else if (!want && raf) {
    cancelAnimationFrame(raf)
    raf = 0
  }
}

function onVisibility() {
  tabVisible = !document.hidden
  syncLoop()
}

let io = null

function watchOnScreen() {
  const el = canvasRef.value
  if (!el || typeof IntersectionObserver === 'undefined') return
  io = new IntersectionObserver(
    (entries) => {
      onScreen = entries[entries.length - 1].isIntersecting
      syncLoop()
    },
    { rootMargin: '200px 0px' },
  )
  io.observe(el)
}

// ---------------------------- 生命周期 ----------------------------

onMounted(() => {
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches

  if (!initGL()) {
    // canvas 一旦绑了 webgl2 就再也拿不到 2d 上下文，所以必须分支
    if (gl) {
      gl.clearColor(COL_BG[0], COL_BG[1], COL_BG[2], 1)
      gl.clear(gl.COLOR_BUFFER_BIT)
      console.info('[DotMatrix] WebGL2 初始化失败，降级为纯色背景。')
    } else {
      console.info('[DotMatrix] 无 WebGL2，背景留空。')
    }
    return
  }

  resize()

  if (reduced) {
    console.info('[DotMatrix] 检测到 prefers-reduced-motion，只渲染静态一帧。')
    staticOnly = true
    drawFrame(0)
    return
  }

  startedAt = performance.now()
  watchOnScreen()
  syncLoop()
  window.addEventListener('pointermove', onPointerMove, { passive: true })
  document.addEventListener('pointerleave', onPointerLeave)
  window.addEventListener('resize', onResize)
  document.addEventListener('visibilitychange', onVisibility)
})

onBeforeUnmount(() => {
  clearTimeout(resizeTimer)
  cancelAnimationFrame(raf)
  if (io) io.disconnect()
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
  height: 100svh;
  pointer-events: none;
}
</style>
