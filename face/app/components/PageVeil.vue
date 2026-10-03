<script setup lang="ts">
import { usePageVeil } from '~/composables/usePageVeil'

/**
 * 换页过渡：上下两扇"闸门"合拢 → 中缝亮起一道光刃 → 换页 → 反向揭开。
 * 做法与 admin 的 PageVeil 完全一致（那边是进控制台时用），这里用在
 * 页头「登录/注册」→ 用户中心这一跳上。
 *
 * 为什么用闸门，而不是常见的圆形擦除 / 整页缩放：
 *   1) 只动 transform，全程走合成层，不掉帧（圆形擦除要动 clip-path，每一帧都要重绘）；
 *   2) 两扇门相遇于中线，那道缝天然就是"加载指示"的落点，信息有地方摆；
 *   3) 合拢=切断、揭开=打开，方向和页面推进的方向一致，不会让人觉得在"退回去"。
 *
 * 组件必须挂在路由出口之外（见 app.vue）—— 它要活得比任何单个页面久，
 * 否则页面一走，遮罩跟着卸载，过渡就断了。
 *
 * 遮罩常驻 DOM 但不加 visibility 切换：两扇门停在视口外、光刃 opacity 0，
 * 视觉上完全不存在，pointer-events 也已经关掉。
 * 换成 visibility 切换的话，元素刚变可见的那一帧 transform 过渡有概率被跳过。
 */
const { phase } = usePageVeil()
</script>

<template>
  <div class="veil" :class="`is-${phase}`" aria-hidden="true">
    <div class="half half-top">
      <span class="half-grid" />
      <span class="half-sheen" />
    </div>
    <div class="half half-bottom">
      <span class="half-grid" />
      <span class="half-sheen" />
    </div>

    <!-- 中缝光刃 -->
    <div class="blade" />

    <!-- 加载指示：刻意压在中线偏下，避开光刃 -->
    <div class="hud">
      <svg class="hud-mark" viewBox="0 0 24 24" aria-hidden="true">
        <path
          d="M12 1.6c.6 4.9 1.7 6 6.6 6.6-4.9.6-6 1.7-6.6 6.6-.6-4.9-1.7-6-6.6-6.6 4.9-.6 6-1.7 6.6-6.6Z"
          fill="currentColor"
        />
      </svg>
      <p class="hud-text">正在载入</p>
      <span class="hud-bar"><i /></span>
    </div>
  </div>
</template>

<style scoped>
.veil {
  position: fixed;
  inset: 0;
  /* 要盖在页头（50）和噪点层（60）之上 */
  z-index: 70;
  pointer-events: none;
}

/* ── 两扇闸门 ─────────────────────────────────────────────────────── */

.half {
  position: absolute;
  left: 0;
  right: 0;
  /* 各占一半再多一点点，闭上时中间不会留出 1px 的亮缝 */
  height: 50.2%;
  overflow: hidden;
  background:
    radial-gradient(120% 220% at 50% 100%, rgba(16, 24, 48, 0.9), transparent 62%),
    #05070e;
  will-change: transform;
  /* 合拢：两头慢中间快，像闸门被放下来 */
  transition: transform 0.56s cubic-bezier(0.65, 0, 0.35, 1);
}

.half-top {
  top: 0;
  transform: translateY(-101%);
}

.half-bottom {
  bottom: 0;
  transform: translateY(101%);
  background:
    radial-gradient(120% 220% at 50% 0%, rgba(16, 24, 48, 0.9), transparent 62%),
    #05070e;
}

.is-covering .half,
.is-covered .half {
  transform: translateY(0);
}

/* 揭开：先窜出去再缓停，比合拢更利落（合拢要"稳"，揭开要"爽"） */
.is-revealing .half {
  transition-duration: 0.72s;
  transition-timing-function: cubic-bezier(0.22, 1, 0.36, 1);
}

.half-grid {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgba(110, 231, 255, 0.07) 1px, transparent 1px),
    linear-gradient(90deg, rgba(110, 231, 255, 0.07) 1px, transparent 1px);
  background-size: 48px 48px;
  opacity: 0.55;
}

.half-sheen {
  position: absolute;
  inset: -30% -20%;
  background: linear-gradient(
    100deg,
    transparent 34%,
    rgba(110, 231, 255, 0.1) 50%,
    transparent 66%
  );
  opacity: 0;
}

/* 斜向高光只在遮罩真的在场时跑，闲着的时候不占渲染 */
.is-covering .half-sheen,
.is-covered .half-sheen {
  opacity: 1;
  animation: veil-sheen 1.7s ease-in-out infinite;
}

/* ── 中缝光刃 ─────────────────────────────────────────────────────── */

.blade {
  position: absolute;
  left: 0;
  right: 0;
  top: 50%;
  height: 2px;
  margin-top: -1px;
  background: linear-gradient(
    90deg,
    transparent,
    rgba(110, 231, 255, 0.85) 18%,
    #ffffff 50%,
    rgba(110, 231, 255, 0.85) 82%,
    transparent
  );
  box-shadow:
    0 0 22px 5px rgba(110, 231, 255, 0.5),
    0 0 70px 22px rgba(110, 231, 255, 0.22);
  opacity: 0;
  transform: scaleX(0.16);
  transition:
    opacity 0.34s ease,
    transform 0.56s cubic-bezier(0.65, 0, 0.35, 1);
}

.is-covering .blade,
.is-covered .blade {
  opacity: 1;
  transform: scaleX(1);
}

/* 揭开时光刃被横向拉长、散掉，像被两扇门带开 */
.is-revealing .blade {
  opacity: 0;
  transform: scaleX(1.4);
  transition:
    opacity 0.5s ease 0.12s,
    transform 0.7s cubic-bezier(0.22, 1, 0.36, 1);
}

/* ── 加载指示 ─────────────────────────────────────────────────────── */

.hud {
  position: absolute;
  left: 50%;
  /* 压在中线偏下，避开光刃 */
  top: calc(50% + 54px);
  transform: translateX(-50%);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 13px;
  opacity: 0;
  transition: opacity 0.32s ease;
}

.is-covering .hud,
.is-covered .hud {
  opacity: 1;
  transition-delay: 0.38s; /* 等门快合上了再亮出来 */
}

.is-revealing .hud {
  opacity: 0;
  transition-delay: 0s;
  transition-duration: 0.18s;
}

.hud-mark {
  width: 20px;
  height: 20px;
  color: var(--cyan);
  filter: drop-shadow(0 0 10px rgba(110, 231, 255, 0.9));
  animation: veil-mark 2s ease-in-out infinite;
}

.hud-text {
  margin: 0;
  font-size: 11.5px;
  font-weight: 500;
  /* 汉字不跟拉丁那套大得多的字距（会散成一片），0.2em 左右是舒适区 */
  letter-spacing: 0.2em;
  text-indent: 0.2em;
  color: rgba(169, 177, 198, 0.9);
}

.hud-bar {
  position: relative;
  display: block;
  width: 168px;
  height: 2px;
  overflow: hidden;
  background: rgba(255, 255, 255, 0.08);
}

.hud-bar i {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 42%;
  background: linear-gradient(90deg, transparent, var(--cyan), transparent);
  animation: veil-run 1.15s ease-in-out infinite;
}

/* ── 动画 ─────────────────────────────────────────────────────────── */

@keyframes veil-run {
  from {
    transform: translateX(-130%);
  }
  to {
    transform: translateX(300%);
  }
}

@keyframes veil-sheen {
  from {
    transform: translateX(-62%);
  }
  to {
    transform: translateX(62%);
  }
}

@keyframes veil-mark {
  50% {
    opacity: 0.45;
  }
}

/* 关掉动效时：时长压到瞬时，但两扇门仍然会"到位"，换页照常不穿帮 */
@media (prefers-reduced-motion: reduce) {
  .half,
  .blade,
  .hud {
    transition-duration: 0.01ms !important;
    transition-delay: 0ms !important;
  }

  .is-covering .half-sheen,
  .is-covered .half-sheen,
  .hud-mark,
  .hud-bar i {
    animation: none;
  }
}
</style>
