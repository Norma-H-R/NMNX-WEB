<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { transitionTo } from '~/composables/usePageVeil'

/**
 * 流光通知条。
 *
 * 动效与**排布方式**都照搬 E:\code\资料加载动效\通知动画.html：
 *
 *   · 位置：整条的 Y 轴不靠 top / flex 排，而是写成 `--y-offset` 变量，塞进 transform。
 *     这样当队列里前一条被移除、本条的序号往前挪时，**只有变量变了**，
 *     transform 的 transition 会自动把它平滑地往上带走 —— 原版
 *     recalculatePositions() 干的就是这件事。
 *
 *   · 三段动作：① 整条从右侧横向切入（X 轴）；② 一道"滑块"追上来盖住整条、
 *     再回弹着退出去（原版是白 + 品红两层，这里换成亮青 + 靛紫，都带白芯）；
 *     ③ 盖布退场的同时文字用 clip-path 从左往右揭示。
 *
 * 相对原版的调整（需求点名的三处）：
 *   1. 颜色与时间：品红换青紫，总时长 4.5s → 6s（可传 ttl 覆盖）；
 *   2. 只暴露两个文字入口：title（主要）/ text（次要）；
 *   3. 宽高：320×90 → 300×90（宽度收窄，高度沿用原值 —— 位置要靠固定高度算，
 *      这一条是照搬排布逻辑的前提，所以不能像纯 flex 那样让内容自由撑高）。
 */

const props = withDefaults(
  defineProps<{
    /** 主要文字 */
    title: string
    /** 次要文字（只有这两个文字在） */
    text?: string
    /** 队列里的纵向位置(px)，由容器按 序号 × (条高 + 间距) 算出来 */
    offset?: number
    /** 停留时长(ms)，到点自动退场 */
    ttl?: number
    /**
     * 详情页路径。传了就表示这条「可点击」：右侧出现一个「›」标识，
     * 点整条直接进详情页；不传就是纯通报，不可点。
     */
    to?: string
  }>(),
  { text: '', offset: 0, ttl: 6000, to: '' },
)

const emit = defineEmits<{ (e: 'close'): void }>()

// 三段时序（原版 200 / 948 / ttl，按同一比例排，总时长收到 6s）
const SLIDE_AT = 180
const REVEAL_AT = 900

const mounted = ref(false) // 挂载后再切类，否则首帧就带着终态、动画不播
const sliding = ref(false)
const revealed = ref(false)
const leaving = ref(false)

let timers: number[] = []

function clearTimers() {
  timers.forEach(clearTimeout)
  timers = []
}

function leave() {
  if (leaving.value) return
  clearTimers()
  leaving.value = true
  // 等退场过渡播完再通知容器移除（容器一移除，下面的条目就会往上补位）
  timers.push(window.setTimeout(() => emit('close'), 460))
}

/**
 * 点整条 → 进详情页，走的是站内那套闸门过渡（先合拢屏再换页）。
 * 顺序上先让它自己退场、再发起跳转：退场 460ms 比合拢 560ms 短，
 * 闸门盖住屏幕之前它已经滑出去了，不会僵在遮罩底下。
 */
function onBodyClick() {
  if (!props.to) return

  const dest = props.to
  leave()
  transitionTo(() => navigateTo(dest))
}

onMounted(() => {
  // 双 rAF：确保首帧已按"未入场"的样式渲染过，下一帧再切类，过渡才会真正播
  requestAnimationFrame(() =>
    requestAnimationFrame(() => {
      mounted.value = true

      timers.push(
        window.setTimeout(() => (sliding.value = true), SLIDE_AT),
        window.setTimeout(() => (revealed.value = true), REVEAL_AT),
        window.setTimeout(leave, props.ttl),
      )
    }),
  )
})

onBeforeUnmount(clearTimers)

const classes = computed(() => ({
  'is-in': mounted.value && !leaving.value,
  'is-sliding': sliding.value,
  'is-revealed': revealed.value,
  'is-leaving': leaving.value,
}))
</script>

<template>
  <!-- 不在这里 Teleport：容器已经整块挂到 body 了，
       自己再传一次会脱离容器（位置也就没人给它算了） -->
  <div
    class="toast"
    :class="[classes, { 'is-clickable': !!to }]"
    :style="{ '--y-offset': `${offset}px` }"
    :data-offset="offset"
    role="status"
    aria-live="polite"
    @click="onBodyClick"
  >
    <!-- 滑块的两层：青在下、金在上，错开一点时间追上来 -->
    <span class="toast__slide toast__slide--main" aria-hidden="true" />
    <span class="toast__slide toast__slide--shadow" aria-hidden="true" />

    <button type="button" class="toast__close" aria-label="关闭通知" @click.stop="leave" />

    <div class="toast__content">
      <p class="toast__title">{{ title }}</p>
      <p v-if="text" class="toast__text">{{ text }}</p>
    </div>

    <!-- 可点击的标识：右下角一个「›」。不可点的通知不出现这个符号 -->
    <span v-if="to" class="toast__go" aria-hidden="true">›</span>
  </div>
</template>

<style scoped>
.toast {
  position: absolute;
  top: 0;
  right: 0;
  /* 宽 300（原版 320 收窄）；高度固定 90 —— 位置要靠它算，见脚本顶部说明 */
  width: 300px;
  height: 90px;
  /* 描边取低饱和的青：纯中性在深色页面上看不见，纯青又太像灯条，走中间 */
  border: 1px solid rgba(110, 231, 255, 0.18);
  border-radius: 14px;
  margin-right: 20px;
  /* 底色比站内卡片实、亮一档：太透的玻璃压在深色页面上会直接"消失" */
  background: linear-gradient(180deg, rgba(22, 27, 44, 0.985), rgba(10, 12, 22, 0.995));
  -webkit-backdrop-filter: blur(18px) saturate(140%);
  backdrop-filter: blur(18px) saturate(140%);
  box-shadow:
    0 26px 60px -30px rgba(0, 0, 0, 0.95),
    0 0 30px -14px rgba(110, 231, 255, 0.5),
    inset 0 1px 0 rgba(255, 255, 255, 0.07);
  overflow: hidden;
  will-change: transform, opacity;

  /* 未入场：X 推出去，Y 停在队列算好的位置上 */
  transform: translate3d(calc(100% + 24px), var(--y-offset, 0px), 0);
  opacity: 0;
  /* transform 的过渡同时管两件事：横向切入，以及**序号前移时的上浮** */
  transition:
    transform 0.55s cubic-bezier(0.16, 1, 0.3, 1),
    opacity 0.3s linear;
}

.toast.is-in {
  transform: translate3d(0, var(--y-offset, 0px), 0);
  opacity: 1;
}

.toast.is-leaving {
  transform: translate3d(calc(100% + 24px), var(--y-offset, 0px), 0);
  opacity: 0;
}

/* ---------------------------- 滑块 ---------------------------- */
.toast__slide {
  position: absolute;
  inset: 0;
  pointer-events: none;
  will-change: transform;
  transform: translate3d(101%, 0, 0);
}

/* 青在下层：先追上来。
   上一版这里是"纯白芯 + 强发光"，压在深色页面上像根灯带，太吵 ——
   改成低饱和的青，光收在自己的色相里 */
.toast__slide--main {
  z-index: 2;
  background: linear-gradient(
    100deg,
    rgba(110, 231, 255, 0.5),
    rgba(110, 231, 255, 0.92) 55%,
    rgba(110, 231, 255, 0.45)
  );
  box-shadow: 0 0 26px -8px rgba(110, 231, 255, 0.62);
}

/* 金在上层：稍慢一拍，露在青色后面当"拖影"。
   站点里金色本来就管"等级 / 积分 / 需注意"，拿它当通知的第二色，比原先的靛紫自然 ——
   青紫那对儿太像 sci-fi 灯条了，和页面的沉调子不搭 */
.toast__slide--shadow {
  z-index: 3;
  background: linear-gradient(
    100deg,
    rgba(242, 209, 141, 0.46),
    rgba(242, 209, 141, 0.9) 55%,
    rgba(242, 209, 141, 0.42)
  );
  box-shadow: 0 0 26px -8px rgba(242, 209, 141, 0.55);
}

.toast.is-sliding .toast__slide--main {
  animation: slide-main 1.05s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

.toast.is-sliding .toast__slide--shadow {
  animation: slide-shadow 1.05s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

/* 覆盖到位 → 回弹 → 退出去（原版 bounceMain / bounceShadow 的时序） */
@keyframes slide-main {
  0% {
    transform: translate3d(101%, 0, 0);
  }
  40%,
  68% {
    transform: translate3d(0, 0, 0);
  }
  100% {
    transform: translate3d(101%, 0, 0);
  }
}

@keyframes slide-shadow {
  0%,
  15% {
    transform: translate3d(101%, 0, 0);
  }
  52%,
  53% {
    transform: translate3d(0, 0, 0);
  }
  82%,
  100% {
    transform: translate3d(101%, 0, 0);
  }
}

/* ---------------------------- 内容 ---------------------------- */
.toast__content {
  position: relative;
  z-index: 4;
  display: flex;
  flex-direction: column;
  justify-content: center;
  height: 100%;
  padding: 0 16px;
  /* 未揭示：整块从左往右擦出来 */
  clip-path: inset(0 100% 0 0);
}

.toast.is-revealed .toast__content {
  animation: text-reveal 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards;
}

@keyframes text-reveal {
  0% {
    clip-path: inset(0 100% 0 0);
  }
  100% {
    clip-path: inset(0 0 0 0);
  }
}

.toast__title {
  padding-right: 18px;
  font-size: 13.5px;
  font-weight: 500;
  line-height: 1.5;
  color: #fff;
  /* 固定高度下，标题最多两行 */
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.toast__text {
  margin-top: 4px;
  padding-right: 18px;
  font-size: 12px;
  line-height: 1.55;
  /* 比 --muted 亮一档，副文也得看得清 */
  color: var(--text-dim);
  /* 副文只占一行，保证"两行标题 + 一行副文"塞得进 90px */
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* ---------------------------- 可点击的标识 ---------------------------- */
/* 整条可点：手势 + 悬停时描边提亮，右下角再给一个「›」 */
.toast.is-clickable {
  cursor: pointer;
}

.toast.is-clickable:hover {
  border-color: rgba(110, 231, 255, 0.42);
}

.toast__go {
  position: absolute;
  right: 11px;
  bottom: 6px;
  z-index: 5;
  font-size: 16px;
  line-height: 1;
  color: var(--cyan);
  opacity: 0.65;
  transition: transform 0.3s var(--ease), opacity 0.3s var(--ease);
}

.toast.is-clickable:hover .toast__go {
  opacity: 1;
  transform: translateX(2px);
}

/* ---------------------------- 关闭 ---------------------------- */
.toast__close {
  position: absolute;
  top: 10px;
  right: 10px;
  z-index: 5;
  width: 20px;
  height: 20px;
  padding: 0;
  border: 0;
  background: transparent;
  cursor: pointer;
  opacity: 0.32;
  transition: opacity 0.25s var(--ease);
}

.toast__close::before,
.toast__close::after {
  content: '';
  position: absolute;
  top: 50%;
  left: 2px;
  width: 16px;
  height: 1.5px;
  border-radius: 2px;
  background: #fff;
}

.toast__close::before {
  transform: rotate(45deg);
}

.toast__close::after {
  transform: rotate(-45deg);
}

.toast__close:hover {
  opacity: 0.85;
}

/* 滑块在上面飞的时候压住关闭按钮，免得点到一个正在被盖住的东西 */
.toast.is-sliding:not(.is-revealed) .toast__close {
  pointer-events: none;
}

@media (max-width: 520px) {
  .toast {
    width: auto;
    left: 0;
    margin: 0 16px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .toast {
    transition-duration: 0.01ms;
  }

  /* 装饰性的滑块与揭示动画直接停掉，内容保持可见 */
  .toast.is-sliding .toast__slide--main,
  .toast.is-sliding .toast__slide--shadow,
  .toast.is-revealed .toast__content {
    animation: none;
  }

  .toast__content {
    clip-path: none;
  }

  .toast__slide {
    display: none;
  }
}
</style>
