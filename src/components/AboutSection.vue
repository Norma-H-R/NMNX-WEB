<script setup>
// 三条轨道各自转速不同，做出一层层套叠的天体感
const orbits = [
  { r: 66, dur: 13, dot: 3.4, reverse: false },
  { r: 118, dur: 21, dot: 2.8, reverse: true },
  { r: 170, dur: 33, dot: 2.2, reverse: false },
]
</script>

<template>
  <section id="about" class="section about">
    <div class="container about__grid">
      <div class="about__text" v-reveal="{ selector: '.rv', stagger: 0.1 }">
        <p class="eyebrow rv">理念</p>
        <h2 class="title rv">
          把交易执行<br />
          做到<em>安静而准确</em>
        </h2>
        <p class="lead rv">
          南门拈星是一套主控 + 跟随端的量化交易系统。主控负责决策与下单，
          跟随端通过命名管道直驱，把指令送到每一台终端。
        </p>
        <p class="lead rv">
          链路全程在本地完成，不依赖第三方中转。授权采用离线激活码，
          绑定账号、经纪商与有效期，一次编译即可分发给不同客户。
        </p>
      </div>

      <div class="about__visual" v-reveal="{ y: 0, duration: 1.4 }">
        <svg class="orbit" viewBox="0 0 420 420" aria-hidden="true">
          <defs>
            <radialGradient id="core" cx="50%" cy="50%" r="50%">
              <stop offset="0%" stop-color="#6ee7ff" stop-opacity=".95" />
              <stop offset="55%" stop-color="#a78bfa" stop-opacity=".35" />
              <stop offset="100%" stop-color="#a78bfa" stop-opacity="0" />
            </radialGradient>
          </defs>

          <circle cx="210" cy="210" r="52" fill="url(#core)" />

          <g v-for="(o, i) in orbits" :key="i" class="orbit__g">
            <circle class="orbit__ring" cx="210" cy="210" :r="o.r" />
            <g
              class="orbit__spin"
              :style="{ animationDuration: o.dur + 's', animationDirection: o.reverse ? 'reverse' : 'normal' }"
            >
              <circle class="orbit__dot" cx="210" :cy="210 - o.r" :r="o.dot" />
            </g>
          </g>

          <circle class="orbit__hub" cx="210" cy="210" r="3.4" />
        </svg>
      </div>
    </div>
  </section>
</template>

<style scoped>
.about__grid {
  display: grid;
  /* 始终两列：左文右图，任何宽度都不塌成上下堆叠。
     minmax(0, …) 是必须的，否则长文本会把列撑破 */
  grid-template-columns: minmax(0, 1.05fr) minmax(0, 0.95fr);
  gap: clamp(22px, 6vw, 110px);
  align-items: center;
}

.about__visual {
  display: flex;
  justify-content: center;
}

.orbit {
  width: min(100%, 440px);
  height: auto;
  filter: drop-shadow(0 0 60px rgba(110, 231, 255, 0.07));
}

.orbit__ring {
  fill: none;
  stroke: rgba(255, 255, 255, 0.1);
  stroke-width: 1;
}

.orbit__g:nth-child(3) .orbit__ring {
  stroke-dasharray: 3 9;
}
.orbit__g:nth-child(4) .orbit__ring {
  stroke-dasharray: 2 12;
}

.orbit__spin {
  /* transform-box 让旋转轴落在 SVG 视口坐标系，而不是元素自身的包围盒 */
  transform-box: view-box;
  transform-origin: 210px 210px;
  animation: orbit-spin linear infinite;
}

.orbit__dot {
  fill: var(--cyan);
}

.orbit__g:nth-child(3) .orbit__dot {
  fill: var(--violet);
}
.orbit__g:nth-child(4) .orbit__dot {
  fill: var(--gold);
}

.orbit__hub {
  fill: #fff;
}

@keyframes orbit-spin {
  to {
    transform: rotate(360deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .orbit__spin {
    animation: none;
  }
}

/* 窄屏只压缩间距和字号，不改列数 */
@media (max-width: 640px) {
  .about__grid {
    gap: 18px;
  }
}
</style>
