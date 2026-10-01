<script setup>
import { onMounted, ref } from 'vue'
import gsap from 'gsap'

const title = '南门拈星'
const chars = [...title]

const root = ref(null)

onMounted(() => {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return

  const q = gsap.utils.selector(root)

  // onMounted 在首次绘制前同步执行，from() 在这里设初值不会闪
  gsap
    .timeline({ defaults: { ease: 'power4.out' } })
    .from(q('.char'), {
      yPercent: 120,
      opacity: 0,
      duration: 1.3,
      stagger: 0.07,
    })
    .from(q('.hero__eyebrow'), { opacity: 0, x: -18, duration: 0.9 }, 0.15)
    .from(q('.hero__sub'), { opacity: 0, y: 26, duration: 1 }, '-=0.85')
    .from(q('.hero__cta > *'), { opacity: 0, y: 22, duration: 0.9, stagger: 0.09 }, '-=0.7')
    .from(q('.hero__rule'), { scaleX: 0, duration: 1.3, ease: 'power2.inOut' }, '-=0.75')
    .from(q('.hero__meta > *'), { opacity: 0, y: 14, duration: 0.8, stagger: 0.08 }, '-=0.9')
    .from(q('.hero__cue'), { opacity: 0, y: -14, duration: 0.8 }, '-=0.6')
})
</script>

<template>
  <section id="top" ref="root" class="hero">
    <div class="container hero__inner">
      <p class="eyebrow hero__eyebrow">量化交易系统</p>

      <h1 class="hero__title" :aria-label="title">
        <span
          v-for="(c, i) in chars"
          :key="i"
          class="char-mask"
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

/* SSR 首帧遮罩（类由 nuxt.config 的 head 脚本挂在 <html> 上）。
   开启服务端渲染后，HTML 一到达浏览器就会先绘制一遍，而入场动画要等 JS
   加载并注水后才由 GSAP 的 from() 接管。这段时间里首屏元素是"最终可见"
   状态，会闪一下再被隐藏。
   这里先按 GSAP 的起始状态把它们压成透明：
     - 位置差异（translate / scale）靠 opacity: 0 一起遮住，不需要逐个复刻
     - 等组件挂载、GSAP 写好内联样式后，由 app.vue 摘掉 .js-on，遮罩整体失效
   没有 JS 时不会挂这个类，内容照常可见。 */
.js-on .hero__eyebrow,
.js-on .hero__sub,
.js-on .hero__cta > *,
.js-on .hero__meta > *,
.js-on .hero__cue,
.js-on .hero__rule,
.js-on .char {
  opacity: 0;
}
</style>
