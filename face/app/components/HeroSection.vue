<script setup>
// 入场动画是纯 CSS 的（见下方 style），这里只负责把标题拆成单字。
// 每个字带一个 --i 序号，CSS 用它算错开延迟。
const title = '南门拈星'
const chars = [...title]
</script>

<template>
  <section id="top" class="hero">
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

      <div class="hero__cta">
        <a class="btn btn--primary" href="#capability"><span>了解系统</span></a>
        <a class="btn" href="#contact"><span>联系我们</span></a>
      </div>

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
  align-items: center;
  padding: 140px 0 120px;
}

.hero__inner {
  width: 100%;
}

.hero__title {
  margin-top: 26px;
  font-size: clamp(56px, 11.5vw, 164px);
  /* 700 就是微软雅黑的 Bold 档，再往上写数字也没用 */
  font-weight: 700;
  line-height: 1;
  letter-spacing: 0.04em;
  /* 渐变整体调亮、压低对比度，这样描边的边沿才不会看出来 */
  background: linear-gradient(180deg, #ffffff 6%, #e2e9fa 55%, #b6c2de 100%);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
  /* Bold 之上想再重只能靠描边"长胖"。
     paint-order 把描边垫在填充下面，字形变粗但内部笔画不会被糊住。
     想更粗就调这个数值：2px 实测约等于骨架 +4px。
     注意它不随字号缩放，小屏要单独收细，否则相对会显得过重 */
  -webkit-text-stroke: 2px #e8efff;
  paint-order: stroke fill;
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

.hero__cta {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  margin-top: 44px;
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
     .hero__cta > *   0.96 + i*0.09 / 0.9s
     .hero__rule      1.20          / 1.3s  power2.inOut
     .hero__meta > *  1.60 + i*0.08 / 0.8s
     .hero__cue       1.96          / 0.8s
   ========================================================================== */

.char,
.hero__eyebrow,
.hero__sub,
.hero__cta > *,
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

.hero__cta > * {
  --rise: 22px;
  animation-name: hero-rise;
  animation-duration: 0.9s;
}
.hero__cta > *:nth-child(1) {
  animation-delay: 0.96s;
}
.hero__cta > *:nth-child(2) {
  animation-delay: 1.05s;
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
  .hero__cta > *,
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

/* 描边是绝对像素，不随 clamp 缩放，小屏按断点收细才不会显得过重 */
@media (max-width: 900px) {
  .hero__title {
    -webkit-text-stroke-width: 1.5px;
  }
}

@media (max-width: 680px) {
  .hero__cue {
    display: none;
  }
}

@media (max-width: 560px) {
  .hero__title {
    -webkit-text-stroke-width: 1px;
  }
}
</style>
