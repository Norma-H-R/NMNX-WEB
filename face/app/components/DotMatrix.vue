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

// 云"沸腾"速度：3D 噪声第 3 维（时间轴）的推进速度。
// 这里刻意不做任何坐标平移 —— 平移会让整片云朝同一个方向滑、形状还不变，
// 那就是"统一运动 + 固定图形"。改走时间维，云才会在原地随机生灭。
const TIME_RATE = 0.06
// 噪声密度。别调太小：密度低时屏幕上"能出极值的团"数量很少，
// 全局粒子数会随时间大幅波动（实测 0.7% ↔ 12%），做不到"始终一定数量"。
// 放细之后同样的阈值下团更多，数量才稳。
const DENSITY = 3.0
// 静态域扭曲强度：只塑造云的形态，不随时间变化，因此不引入方向性运动。
const WARP = 1.6
const PIX = 6 // 方块边长（CSS 像素）：在原来 4 的基础上放大 1.5 倍
const GAP = 2 // 方块间隙（CSS 像素）
// 云量映射：把噪声 n 映射成"这一块被选中的概率 p"。
// 刻意不用 "n > 阈值" 那种尾部二值判断 —— 尾部对场的均值波动极其敏感，
// 全局粒子数会大幅跳（实测 0.6% ↔ 6%，做不到"始终一定数量"）。
// 换成有上限的平滑映射后，覆盖率期望 = p 的空间均值，落在分布的中间段，数量才稳。
const CLOUD_LO = 0.35
const CLOUD_HI = 0.72
const CLOUD_MAX = 0.28 // 概率上限（越大整体越密）
const COLOR_RATE = 0.5 // 颜色场的演化速率（越大变色越快）
// 颜色场的空间尺度：越大越细碎 —— 变色会以"零星十几到几十个"成簇出现，
// 而不是一整片一起刷白/刷青。
const COLOR_SCALE = 3.5
const ACCENT_RATIO = 0.45 // 强调色（青）占比，其余为白
const MOUSE_R = 0.18 // 鼠标影响半径（UV）
const MOUSE_K = 1.0 // 鼠标变色强度

// 空间分布：点阵铺在 hero 区块内，密度自顶向下递减
// （对齐参考站实测的自顶 12 段密度 71,100,94,72,57,51,48,…,30）
const TAPER_MIN = 0.7 // 底部的概率下限（相对顶部）

// 颜色与 main.css 的设计变量一致
const COL_ACCENT = [110 / 255, 231 / 255, 255 / 255] // --cyan
const COL_WHITE = [1.0, 1.0, 1.0]
const COL_BG = [6 / 255, 7 / 255, 13 / 255] // --bg

// 3D 噪声比 2D 贵（每个八度要取 8 个角点），而且输出本来就是 6px 的方块，
// 高 DPR 对观感没有收益，所以这里收一档，省下大量像素成本。
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

#define TIME_RATE ${TIME_RATE.toFixed(4)}
#define DENSITY ${DENSITY.toFixed(4)}
#define WARP ${WARP.toFixed(4)}
#define PIX ${PIX.toFixed(1)}
#define GAP ${GAP.toFixed(1)}
#define CLOUD_LO ${CLOUD_LO.toFixed(4)}
#define CLOUD_HI ${CLOUD_HI.toFixed(4)}
#define CLOUD_MAX ${CLOUD_MAX.toFixed(4)}
#define ACCENT_RATIO ${ACCENT_RATIO.toFixed(4)}
#define MOUSE_R ${MOUSE_R.toFixed(4)}
#define MOUSE_K ${MOUSE_K.toFixed(2)}
#define TAPER_MIN ${TAPER_MIN.toFixed(4)}
#define COLOR_RATE ${COLOR_RATE.toFixed(4)}
#define COLOR_SCALE ${COLOR_SCALE.toFixed(4)}
#define COL_ACCENT vec3(${COL_ACCENT.map((v) => v.toFixed(4)).join(',')})
#define COL_WHITE vec3(${COL_WHITE.map((v) => v.toFixed(4)).join(',')})
#define COL_BG vec3(${COL_BG.map((v) => v.toFixed(5)).join(',')})

// ---- 哈希 ----
// 刻意不用 fract(sin(dot(p, 大常数)))：sin 的参数一大（这里能到上万）float 精度就崩，
// 哈希会退化成带空间条带的伪随机 —— 表现是噪声出现方向性偏差、云固定聚在一侧。
// 这里用只靠乘加的哈希，全精度范围内都稳。
float vhash(vec2 p) {
  vec3 p3 = fract(vec3(p.xyx) * 0.1031);
  p3 += dot(p3, p3.yzx + 33.33);
  return fract((p3.x + p3.y) * p3.z);
}

float vhash3(vec3 p) {
  p = fract(p * 0.1031);
  p += dot(p, p.zyx + 31.32);
  return fract((p.x + p.y) * p.z);
}

// ---- 2D 值噪声：只给静态域扭曲用（不随时间，不引入方向性）----
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

// ---- 3D 值噪声：第 3 维当时间轴用 ----
// 这是"原地随机沸腾"的关键。把时间当成一根空间轴来采样，噪声场会自己不断变化，
// 而不是沿某个方向整体平移 —— 平移就是"统一运动 + 形状不变"。
float vnoise3(vec3 p) {
  vec3 i = floor(p);
  vec3 f = fract(p);
  vec3 u = f * f * (3.0 - 2.0 * f);
  float n000 = vhash3(i);
  float n100 = vhash3(i + vec3(1.0, 0.0, 0.0));
  float n010 = vhash3(i + vec3(0.0, 1.0, 0.0));
  float n110 = vhash3(i + vec3(1.0, 1.0, 0.0));
  float n001 = vhash3(i + vec3(0.0, 0.0, 1.0));
  float n101 = vhash3(i + vec3(1.0, 0.0, 1.0));
  float n011 = vhash3(i + vec3(0.0, 1.0, 1.0));
  float n111 = vhash3(i + vec3(1.0, 1.0, 1.0));
  return mix(
    mix(mix(n000, n100, u.x), mix(n010, n110, u.x), u.y),
    mix(mix(n001, n101, u.x), mix(n011, n111, u.x), u.y),
    u.z);
}

float fbm3(vec3 p) {
  // 八度权重取"平"而不是按 0.5 递减：递减时极值由最低频层主导，
  // 高阈值下只剩几坨，云会聚在某一侧。
  float v = 0.0;
  v += 0.45 * vnoise3(p);
  v += 0.33 * vnoise3(p * 2.03);
  v += 0.22 * vnoise3(p * 4.09);
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

  // 各向同性映射：uv 两轴都是 0~1，而画布宽高比约 1.76，
  // 直接用会让噪声横向被拉长、云挤到某一侧。按宽高比校正 x 之后云块接近正方。
  float asp = uRes.x / uRes.y;
  vec2 nBase = vec2(blockUV.x * asp, blockUV.y) * DENSITY;

  // 静态域扭曲：只把采样点推开一点来塑造云的形态。
  // 它不随时间变化，所以不会带来任何方向性运动。
  vec2 warp = vec2(vnoise(nBase * 0.6), vnoise(nBase * 0.6 + vec2(17.0, 5.0))) - 0.5;
  vec2 nw = nBase + warp * WARP;

  // 密度场：3D 噪声，第 3 维是时间 → 云在原地随机生灭。
  // 既不会整体平移（那是"统一运动"），也不会保持同一个形状（那是"固定图形"）。
  float val = fbm3(vec3(nw, uTime * TIME_RATE));

  // ---- 云量概率 ----
  // 把噪声映射成"这一块此刻被选中的概率"。不直接对 n 做二值化，原因见 CLOUD_* 注释。
  float p = CLOUD_MAX * smoothstep(CLOUD_LO, CLOUD_HI, val);

  // 空间分布：自顶向下递减
  float yTop = 1.0 - uv.y; // 自顶向下 0→1（uv.y 自底向上）
  p *= mix(1.0, TAPER_MIN, smoothstep(0.0, 0.95, yTop));

  // 每块一个稳定的随机数做二值化：实际选中比例精确等于 p。
  // 于是全局数量稳定（不会被分布的尾部放大），局部又随 p 的演化随机生灭。
  float keep = vhash(blockId + 7.0);

  vec3 col = COL_BG;
  if (keep < p) {
    // 颜色用"空间 + 时间"的噪声决定：空间尺度小 → 变色以零散小簇
    // （十几个、几十个）成片出现，而不是一整片一起刷白/刷青；时间维 → 这些簇缓慢演化。
    float cfield = vnoise3(vec3(nBase * COLOR_SCALE, uTime * COLOR_RATE));
    col = cfield > (1.0 - ACCENT_RATIO) ? COL_ACCENT : COL_WHITE;

    // 鼠标：把附近粒子整体换成**同一种颜色**。
    // 这里刻意不用"按距离平滑衰减"——那会插值出一圈圆形渐变的环。
    // 改成用每块的随机数做二值判断：越靠近光标，被选中的概率越高，
    // 于是选中区域的边界是随机锯齿（形状随机），每个粒子深浅也随机。
    float d = distance(uv, uMouse);
    if (d < MOUSE_R) {
      float pickShape = vhash(blockId + 31.0);
      float pickDepth = vhash(blockId + 53.0);
      float near = 1.0 - d / MOUSE_R;
      if (pickShape < near * MOUSE_K) {
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
  // 跟手快一点：之前 0.12 太拖，颜色变化会明显滞后于鼠标
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
