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

// 页脚目录。原来这里是「理念 / 能力 / 联系」三项，按要求换掉。
// 前五项落到站内区块；后四项指向独立页面（pages/blog|forum|reports|articles），
// 那些页面目前是占位页，但链接是通的，不会 404。
// 以后再添还没做的入口，可以只写 label 不给 href —— 会渲染成不可点的灰项 .ftr__soon。
const dir = [
  { label: '观摩领取', href: '#contact' },
  { label: '测试领取', href: '#contact' },
  { label: '项目介绍', href: '#about' },
  { label: '产品介绍', href: '#capability' },
  { label: '问题反馈', href: '#contact' },
  { label: '博客', href: '/blog' },
  { label: '论坛', href: '/forum' },
  { label: '回测报告', href: '/reports' },
  { label: '文章', href: '/articles' },
]

// 锚点链接：在首页直接跳区块；在子页（博客 / 论坛…）要带回首页路径，
// 否则点了不动 —— 那些 #about 在子页上并不存在。
const route = useRoute()
const dirHref = (h) => (!h ? '' : h.startsWith('#') && route.path !== '/' ? `/${h}` : h)

// ---------------------------- 可调参数 ----------------------------

const BAND = 9 // 切片高度（CSS 像素），越小吃得越细碎
const BAND_FREQ = 0.3 // 切片方向的相关性：越小，相邻切片越接近、错位越"成块"
const AMP = 34 // 故障切片的最大横向错位（像素）
const SPLIT = 16 // RGB 分裂的最大横向错位（像素）
const SHAKE = 3 // 爆发时的整幅上下抖动（像素）
const SPEED = 1.1 // 切片图案的演化速度

// 色差分裂的两路颜色：亮白 + 亮红。
// 底色仍是品牌青（#6ee7ff）、主字仍是墨色（--bg）—— 两者都不动；
// 青底上白与红都最跳，黑的字压中间，三色互不打架。
// （想要更暖/更粉的红，只改 SPLIT_RED 一处即可。）
const SPLIT_WHITE = '#ffffff'
const SPLIT_RED = '#ff1a1a' // 亮红

// 爆发调度：多久爆发一次、持续多久
const BURST_MIN = 0.26
const BURST_MAX = 0.7
const GAP_MIN = 1.6
const GAP_MAX = 4.4

const INK = '#06070d' // 字色 = --bg（保持不动）
const BRAND = '#6ee7ff' // 底色 = --cyan（保持不动）

// ---------------------------- 状态 ----------------------------

const canvasRef = ref(null)

let ctx = null
let off = null // 离屏画布：墨色整行字
let offWhite = null // 亮白副本（RGB 分裂用）
let offRed = null // 亮红副本（RGB 分裂用）
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
  octx.fillStyle = INK

  // ⚠️ 这里不能用 textBaseline: 'middle'。它居中的是**字体的 em 框**，而墨色在 em 框里
  // 的位置各字体并不一致：这个子集字体（思源黑体 Heavy）的墨色整体偏上，实测画布高 356 时
  // 墨色上边距 0px、下边距 50px —— "南"顶上一横正好被切掉，看着就是"显示得不对"。
  // 改成先量出墨色包围盒（actualBoundingBox*），再按包围盒精确居中：
  // 基线取 h/2 + (上高 - 下深)/2，墨色上下边距就相等了。
  // 老浏览器没有 actualBoundingBox*，退回按 CJK 墨色约占 0.88em 估算。
  const mt = octx.measureText(MARK)
  const asc = Number.isFinite(mt.actualBoundingBoxAscent) ? mt.actualBoundingBoxAscent : size * 0.88
  const desc = Number.isFinite(mt.actualBoundingBoxDescent) ? mt.actualBoundingBoxDescent : size * 0.12
  octx.textBaseline = 'alphabetic'
  octx.fillText(MARK, w / 2, h / 2 + (asc - desc) / 2)

  offWhite = tint(off, SPLIT_WHITE)
  offRed = tint(off, SPLIT_RED)
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

  // 色差分裂：亮白 / 亮红两路左右错开，墨色主字压在中间
  ctx.globalAlpha = g * 0.9
  ctx.drawImage(offWhite, 0, 0, sw, sh, -split, shake, w, h)
  ctx.drawImage(offRed, 0, 0, sw, sh, split, shake, w, h)
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

// 盯住画布**自身**的尺寸变化，而不只是 window resize。
// 少了这一层有个真实的坑：CSS 改了高度而 window 没有 resize（改样式、HMR 热更、
// 字体替换引起重排…）时画布不会重建，浏览器就会拿旧的位图去拉伸铺满新的 CSS 尺寸 ——
// 表现就是"字被拉长了"。有 ResizeObserver 兜住，这种中间态不会出现。
// （buildText 只改画布的像素缓冲，不改它的 CSS 尺寸，所以不会和这个观察器来回触发。）
let ro = null

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
  if (typeof ResizeObserver !== 'undefined' && canvasRef.value) {
    ro = new ResizeObserver(() => onResize())
    ro.observe(canvasRef.value)
  }
})

onBeforeUnmount(() => {
  clearTimeout(resizeTimer)
  cancelAnimationFrame(raf)
  window.removeEventListener('resize', onResize)
  document.removeEventListener('visibilitychange', onVisibility)
  if (ro) ro.disconnect()
})
</script>

<template>
  <footer class="ftr">
    <!-- 信息条（原先这里还有一行「获取授权激活码」的收尾 CTA，已按要求去掉；
         与上方区块的分隔线由本条自己的 border-top 提供，不受影响） -->
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

      <!-- 回到顶部：纯图标，不带文字（导航已移到上方目录行，
           原来挂在它左侧的「理念 / 能力 / 联系」三项按要求去掉）。
           视觉隐藏了文字，但 aria-label / title 仍给读屏与悬停提示。 -->
      <button
        type="button"
        class="ftr__top"
        aria-label="回到顶部"
        title="回到顶部"
        @click="toTop"
      >
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
    </div>

    <!-- 目录：按要求紧贴在免责声明上方。
         有 href 的渲染成可点链接；博客 / 论坛 / 回测报告 / 文章还没有页面，
         渲染成不可点的灰项 .ftr__soon，免得点了跳空。 -->
    <nav class="container ftr__dir" aria-label="站内目录">
      <template v-for="l in dir" :key="l.label">
        <a v-if="l.href" :href="dirHref(l.href)">{{ l.label }}</a>
        <span v-else class="ftr__soon" title="筹备中">{{ l.label }}</span>
      </template>
    </nav>

    <!-- 免责声明：放在字标之上 —— 字标才是页脚最下方那一块 -->
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

/* ---------------------------- 目录 ---------------------------- */
/* 排在信息条与免责声明之间，窄屏自动换行。
   原先是 .ftr__nav 那三个区块链接，已替换成下面这些入口。 */
.ftr__dir {
  display: flex;
  flex-wrap: wrap;
  gap: 12px 34px;
  padding-top: clamp(18px, 2.4vw, 28px);
  padding-bottom: clamp(18px, 2.4vw, 28px);
  font-size: 13.5px;
}

.ftr__dir a {
  color: var(--text-dim);
  transition: color 0.35s var(--ease);
}

.ftr__dir a:hover {
  color: var(--cyan);
}

/* 尚未上线的目录项（博客 / 论坛 / 回测报告 / 文章）：不可点，颜色再压一档，
   与可点项一眼区分开。补上 href 后会自动走 .ftr__dir a 的样式。 */
.ftr__soon {
  color: #5a6178;
  cursor: default;
}

.ftr__top {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  padding: 0;
  border: 1px solid var(--line-strong);
  border-radius: 50%;
  background: none;
  color: var(--text-dim);
  cursor: pointer;
  transition: color 0.35s var(--ease), border-color 0.35s var(--ease);
}

.ftr__top:hover {
  color: var(--cyan);
  border-color: var(--cyan);
}

.ftr__top svg {
  width: 16px;
  height: 16px;
  transition: transform 0.4s var(--ease);
}

.ftr__top:hover svg {
  transform: translateY(-3px);
}

.ftr__note {
  margin: 0;
  /* 排在信息条与字标之间：与字标之间留 44px（信息条自身有 24px 下内边距） */
  padding-bottom: 44px;
  max-width: 78ch;
  font-size: 12.5px;
  line-height: 1.95;
  color: var(--muted);
}

/* ---------------------------- 字标 ----------------------------
   底色整幅铺满，画布限宽居中（与参考站一致：那边也是
   max-w-[1600px] 的画布 + 整幅品牌色）。
   字身大小只由**宽度**决定（四个汉字各占 1em，铺满一行时字高正好是宽度的四分之一），
   所以在 1/4 屏宽之上再上下各留 50px：字不会被拉大，只是上下多出呼吸空间，
   多出来的这 100px 是纯留白（字在画布里按墨色包围盒居中，见 buildText）。 */

.ftr__mark {
  position: relative;
  overflow: hidden;
  /* 与画布填充色（BRAND = --cyan）保持一致：画布出首帧之前露出的就是这层底色 */
  background: var(--cyan);
  height: calc(min(100vw, 1600px) / 4 + 100px);
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
