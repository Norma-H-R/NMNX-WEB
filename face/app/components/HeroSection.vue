<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

// 入场动画是纯 CSS 的（见下方 style），这里只负责把标题拆成单字。
// 每个字带一个 --i 序号，CSS 用它算错开延迟。
const title = '南门拈星'
const chars = [...title]

// ---------------------------------------------------------------------------
// 黑层/磨砂的强度 = 滚动进度 --hero-p（0 → 1）。
//
// 静止：只在下方压黑、标题那一带几乎通透，另加一丁点磨砂把主体文字托出来；
// 下滚：整层加深成完整磨砂，顺势过渡到下一屏底色。
//
// 用 setProperty 直接写 CSS 变量，不走响应式 —— 滚动里每帧都在变，
// 走响应式等于每帧白跑一遍组件渲染。
// ---------------------------------------------------------------------------
const heroRef = ref(null)
// 走完大约 3/4 屏就到达最强，免得整层还没加深 hero 就已经滚出去了
const RAMP = 0.75
let raf = 0

function applyProgress() {
  raf = 0
  const el = heroRef.value
  if (!el) return
  const vh = window.innerHeight || 1
  const p = Math.min(1, Math.max(0, window.scrollY / (vh * RAMP)))
  el.style.setProperty('--hero-p', p.toFixed(3))
}

function onScroll() {
  if (raf) return
  raf = requestAnimationFrame(applyProgress)
}

onMounted(() => {
  applyProgress()
  window.addEventListener('scroll', onScroll, { passive: true })
  window.addEventListener('resize', onScroll, { passive: true })
})

onBeforeUnmount(() => {
  if (raf) cancelAnimationFrame(raf)
  window.removeEventListener('scroll', onScroll)
  window.removeEventListener('resize', onScroll)
})
</script>

<template>
  <section id="top" ref="heroRef" class="hero">
    <!-- 渐变磨砂黑。强度由 --hero-p（滚动进度）驱动，见脚本：
         静止时压黑只在下方、标题区几乎通透；下滚时整层加深成完整磨砂。 -->
    <div class="hero__veil" aria-hidden="true" />

    <div class="container hero__inner">
      <p class="eyebrow hero__eyebrow">量化交易系统</p>

      <h1 class="hero__title" :aria-label="title">
        <span
          v-for="(c, i) in chars"
          :key="i"
          class="char-mask"
          :style="{ '--i': i }"
          aria-hidden="true"
        ><span class="char">{{ c }}</span></span>
      </h1>

      <p class="hero__sub">
        以数据与工程为底，做安静的量化系统。<br />
        不喧哗，不承诺，只把该做的事做到毫秒级。
      </p>

      <div class="hero__rule" />

      <ul class="hero__meta">
        <li><span>架构</span>主控 / 跟随端</li>
        <li><span>链路</span>命名管道直驱</li>
        <li><span>授权</span>离线激活码</li>
      </ul>
    </div>

    <a class="hero__cue" href="#about" aria-label="向下滚动">
      <span class="hero__cue-line" />
      <span class="hero__cue-text">SCROLL</span>
    </a>
  </section>
</template>

<style scoped>
.hero {
  position: relative;
  z-index: 1;
  min-height: 100svh;
  display: flex;
  /* 文字整体压到左下角：纵向贴底，横向见 .hero__inner */
  align-items: flex-end;
  padding: 120px 0 clamp(72px, 9vh, 116px);
}

/* 渐变磨砂黑。强度由 --hero-p（滚动进度 0→1，见脚本）驱动。
   静止（--hero-p:0）：压黑只落在下方，标题那一段几乎通透，只留一丁点磨砂
                       把主体文字从点阵里托出来 —— 不能压太重，否则标题会被埋掉；
   下滚（--hero-p:1）：整层加深成完整磨砂，顺势过渡到下一屏底色，不出现跳变。

   三点说明：
   1. 底色用 --bg(#06070d) 而不是纯 #000 —— 和页面其它区块一致，接下一屏不会有接缝；
   2. 磨砂是 backdrop-filter 真把背后点阵糊掉，不是画一层灰；
   3. 之前把 mask 用在模糊上、而渐变一路压到标题区（标题处在距底 35%~57%，
      那里还留着 0.7~0.36 的黑），所以标题被埋了。现在改成"整层清淡半透明 +
      只在下方加重"，标题区只受很轻的一层。 */
.hero__veil {
  position: absolute;
  inset: 0;
  z-index: 0;
  pointer-events: none;
  /* 半透明盖住一点：静止 0.14 → 滚到底 0.86 */
  background: rgba(6, 7, 13, calc(0.14 + var(--hero-p, 0) * 0.72));
  /* 磨砂：静止 0（不模糊，正常显示）→ 滚到底 11.5px */
  -webkit-backdrop-filter: blur(calc(var(--hero-p, 0) * 11.5px));
  backdrop-filter: blur(calc(var(--hero-p, 0) * 11.5px));
}

/* 压黑只集中在下半屏，往上很快收干净：标题上沿（距底约 57%）处已经基本清零 */
.hero__veil::before {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(
    to top,
    rgba(6, 7, 13, 0.92) 0%,
    rgba(6, 7, 13, 0.66) 18%,
    rgba(6, 7, 13, 0.34) 36%,
    rgba(6, 7, 13, 0.14) 54%,
    rgba(6, 7, 13, 0.04) 74%,
    rgba(6, 7, 13, 0) 92%
  );
}

.hero__inner {
  position: relative;
  /* 必须压过 veil：veil 是定位元素(z-index:0)，非定位的块会排在它下面，
     文字会被那层黑一起压暗。所以这里显式抬到 z-index:1。 */
  z-index: 1;
  width: 100%;
  /* 跳出全局 .container 的居中收窄，让文字贴到屏幕左边 */
  max-width: none;
  padding-left: clamp(20px, 4.5vw, 72px);
  padding-right: clamp(20px, 4.5vw, 72px);
}

.hero__title {
  margin-top: 26px;
  font-size: clamp(56px, 11.5vw, 164px);
  /* 700 就是微软雅黑的 Bold 档，再往上写数字也没用 */
  font-weight: 700;
  line-height: 1;
  letter-spacing: 0.04em;
  /* 纯白平色。原来这里是 渐变填充 + text-stroke 描边 的"特效"，
     按要求去掉了；注意去掉后字面会比之前细一点——那 2px 描边本来就是用来"长胖"的。 */
  color: #fff;
}

/* 每个字外面套一层 overflow:hidden 的遮罩，字从下方滑入 */
.char-mask {
  display: inline-block;
  overflow: hidden;
  vertical-align: bottom;
  padding-bottom: 0.06em;
}

.char {
  display: inline-block;
}

.hero__sub {
  margin-top: 34px;
  max-width: 46ch;
  font-size: clamp(15px, 1.15vw, 17.5px);
  line-height: 2;
  color: var(--text-dim);
}

.hero__rule {
  width: min(560px, 100%);
  height: 1px;
  margin: 62px 0 26px;
  transform-origin: left;
  background: linear-gradient(90deg, var(--line-strong), transparent);
}

.hero__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 12px 46px;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 14px;
  color: var(--text-dim);
  letter-spacing: 0.03em;
}

.hero__meta span {
  margin-right: 12px;
  color: var(--muted);
  font-size: 12px;
  letter-spacing: 0.2em;
}

.hero__cue {
  position: absolute;
  left: 50%;
  bottom: 34px;
  transform: translateX(-50%);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
}

.hero__cue-line {
  width: 1px;
  height: 54px;
  background: linear-gradient(180deg, transparent, var(--cyan));
  animation: cue 2.4s var(--ease-soft) infinite;
}

.hero__cue-text {
  font-size: 10.5px;
  letter-spacing: 0.42em;
  color: var(--muted);
}

@keyframes cue {
  0% {
    transform: scaleY(0.2);
    transform-origin: top;
    opacity: 0;
  }
  40% {
    transform: scaleY(1);
    transform-origin: top;
    opacity: 1;
  }
  100% {
    transform: scaleY(1);
    transform-origin: bottom;
    opacity: 0;
  }
}

/* ==========================================================================
   首屏入场动画 —— 纯 CSS，替代原先的 GSAP timeline

   核心是 animation-fill-mode: backwards：在 delay 期间就应用 from 关键帧，
   动画结束后元素回归自身样式。三个好处：
     1. 服务端渲染出的 HTML 一到达浏览器就是"未入场"状态，不需要额外遮罩，不会闪；
     2. 动画在首次绘制时就开始，不必等 JS 下载 + 注水，用户不会先看到一片空白；
     3. 播完不残留 transform，按钮 :hover 的位移不会被盖住。

   原地用 GSAP timeline 的相对定位（'-=' 往前挪）算出来的绝对时刻，
   依次是「起始时刻 / 时长」：
     .char            i*0.07        / 1.3s  power4.out
     .hero__eyebrow   0.15          / 0.9s
     .hero__sub       0.66          / 1.0s
     .hero__rule      1.20          / 1.3s  power2.inOut
     .hero__meta > *  1.60 + i*0.08 / 0.8s
     .hero__cue       1.96          / 0.8s
   ========================================================================== */

.char,
.hero__eyebrow,
.hero__sub,
.hero__rule,
.hero__meta > *,
.hero__cue {
  animation-fill-mode: backwards;
  /* power4.out —— 和全局 --ease 是同一条曲线 */
  animation-timing-function: var(--ease);
}

.char {
  animation-name: hero-slide;
  animation-duration: 1.3s;
  animation-delay: calc(var(--i, 0) * 0.07s);
}

.hero__eyebrow {
  animation-name: hero-from-left;
  animation-duration: 0.9s;
  animation-delay: 0.15s;
}

/* 上浮距离用变量参数化，避免为几个近似值各写一套关键帧 */
.hero__sub {
  --rise: 26px;
  animation-name: hero-rise;
  animation-duration: 1s;
  animation-delay: 0.66s;
}

.hero__rule {
  animation-name: hero-rule;
  animation-duration: 1.3s;
  animation-delay: 1.2s;
  /* power2.inOut */
  animation-timing-function: cubic-bezier(0.455, 0.03, 0.515, 0.955);
}

.hero__meta > * {
  --rise: 14px;
  animation-name: hero-rise;
  animation-duration: 0.8s;
}
.hero__meta > *:nth-child(1) {
  animation-delay: 1.6s;
}
.hero__meta > *:nth-child(2) {
  animation-delay: 1.68s;
}
.hero__meta > *:nth-child(3) {
  animation-delay: 1.76s;
}

.hero__cue {
  animation-name: hero-cue;
  animation-duration: 0.8s;
  animation-delay: 1.96s;
}

/* 从遮罩下方滑入（对应 GSAP 的 yPercent: 120） */
@keyframes hero-slide {
  from {
    opacity: 0;
    transform: translateY(120%);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes hero-from-left {
  from {
    opacity: 0;
    transform: translateX(-18px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}

@keyframes hero-rise {
  from {
    opacity: 0;
    transform: translateY(var(--rise, 26px));
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes hero-rule {
  from {
    transform: scaleX(0);
  }
  to {
    transform: scaleX(1);
  }
}

/* .hero__cue 自身靠 translateX(-50%) 居中，关键帧里必须把它带上 */
@keyframes hero-cue {
  from {
    opacity: 0;
    transform: translate(-50%, -14px);
  }
  to {
    opacity: 1;
    transform: translate(-50%, 0);
  }
}

@media (prefers-reduced-motion: reduce) {
  .char,
  .hero__eyebrow,
  .hero__sub,
  .hero__rule,
  .hero__meta > *,
  .hero__cue {
    animation: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .hero__cue-line {
    animation: none;
  }
}

@media (max-width: 680px) {
  .hero__cue {
    display: none;
  }
}
</style>
