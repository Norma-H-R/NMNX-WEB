<script setup lang="ts">
import { computed } from 'vue'

/**
 * 产品详情页顶部：**上面是产品名，下面是版本号**。
 *
 * ⚠️ 这套是照 `动效组合/laravel-welcome` 的**手法与参数原样移植**的。
 *    对着原页面截图逐项对齐后的结构是：
 *
 *       上：产品名（单层实心大字，从下往上浮入）
 *       下：**版本号（巨大，5 层横向错开叠成一排重影）** ← 动效主体就是它
 *
 *   原页面下方那个巨大的多层图形其实是版本号「13」，不是 logo ——
 *    我先前把它当成了产品名的陪衬，整个做反了，这里纠正过来。
 *
 * 原版用 Tailwind 的 `starting:` 变体，等价翻译成原生 CSS：
 *
 *   duration-750                   → 750ms
 *   delay-300 / delay-400          → 300ms / 400ms
 *   starting:opacity-0             → @starting-style { opacity: 0 }
 *   starting:translate-y-6         → @starting-style { translate: 0 24px }
 *   starting:-translate-x-[26px]…  → 每层从**同一个起点**散开（见下）
 *   mix-blend-*                    → mix-blend-mode
 *
 * **「散开」的机制**（这是原版最容易被看漏的一点）：
 *   5 层的常态位置本身依次错开一点（原版靠 SVG 路径坐标，这里靠 translate），
 *   而每层的 `starting:-translate-x-[26k]px` 恰好把自己挪回**同一个起点**。
 *   所以视觉上是**5 层从一处向右散开**，不是各滑各的。
 *
 * **一处刻意的不同**：原页面浅色底，层间用 `darken / multiply` 压暗；
 *   我们深色底，压暗会糊成一块黑斑，所以换成 `screen / lighten` 一侧 ——
 *   叠出来是发光重影。颜色仍照原版那套逻辑：多数层压暗、少数层提亮。
 */

const props = withDefaults(
  defineProps<{
    name: string
    version: string
    /** 主题色相，重影的色阶由它派生 */
    hue?: number
  }>(),
  { hue: 192 },
)

/** 5 层，和原版 SVG 的层数一致 */
const LAYERS = [0, 1, 2, 3, 4]

/**
 * 大字用**完整版本号**（`3.4.1`）。
 *
 * 一开始我按原版只取主版本号（就是个「3」），结果五层错开全糊成一坨 ——
 * 原页面那个巨大的「13」是**两个字符**，够宽才撑得住横向错开，
 * 单个数字太窄，五层叠上去就是一块色斑。
 */
const big = computed(() => props.version)
</script>

<template>
  <div class="ph" :style="{ '--h': props.hue }">
    <!-- 上：产品名 -->
    <h1 class="ph__name">{{ name }}</h1>

    <!-- 下：版本号 —— 5 层错开的巨大重影 -->
    <div class="ph__ver" aria-hidden="true">
      <span v-for="i in LAYERS" :key="i" class="ph__layer" :style="{ '--i': i }">
        {{ big }}
      </span>
    </div>

    <!-- 版本号的语义版本放在下面一行，顺带给读屏读 -->
    <p class="ph__meta">
      <span class="ph__v">v{{ version }}</span>
      <span class="ph__dot">·</span>
      <a class="ph__link" href="#intro">查看介绍</a>
    </p>
  </div>
</template>

<style scoped>
.ph {
  position: relative;
  padding: clamp(20px, 3vw, 36px) 0 0;
}

/* ---------------------------- 上：产品名 ---------------------------- */
.ph__name {
  font-size: clamp(30px, 6vw, 62px);
  font-weight: 600;
  line-height: 1.1;
  letter-spacing: -0.01em;
  color: var(--text);

  /* 对应原版那块 wordmark：从下往上浮入，无延迟 */
  opacity: 1;
  translate: 0 0;
  transition:
    translate 750ms cubic-bezier(0.22, 1, 0.36, 1),
    opacity 750ms cubic-bezier(0.22, 1, 0.36, 1);
}

@starting-style {
  .ph__name {
    translate: 0 24px;
    opacity: 0;
  }
}

/* ---------------------------- 下：版本号重影 ---------------------------- */
.ph__ver {
  position: relative;
  /* 给重影留出横向溢出的空间，别被裁掉 */
  height: clamp(76px, 15vw, 178px);
  margin-top: clamp(-6px, -0.6vw, 0px);
}

.ph__layer {
  position: absolute;
  left: 0;
  top: 0;
  display: block;
  font-size: clamp(76px, 15vw, 178px);
  /* 最粗。用户要求：字细了撑不住这种叠影，一叠就只剩灰糊糊一片 */
  font-weight: 900;
  line-height: 1;
  letter-spacing: -0.04em;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;

  /* 各层的色阶：由主题色相派生。**明暗要拉得很开** ——
     原版那五层是「黑 → 暗红 → 金 → 棕 → 暗红」的强对比，
     不是同一个颜色的深浅微调（那样叠出来只会是一团渐变色） */
  color: hsl(
    calc(var(--h) + var(--i) * 22),
    calc(78% - var(--i) * 8%),
    calc(24% + var(--i) * 13%)
  );
  /* 默认 screen；下面再按层逐个覆盖成不同的混合模式 */
  mix-blend-mode: screen;

  /* 常态 = 最终态：停在自己该在的位置。
     错开量**收得很小**：五层基本叠在一起、只错开一丝边缘，
     这样才看得出"同一个字的叠影"；摊太开就散成了好几个字，反而认不出样式 */
  translate: calc(var(--i) * 0.035em) 0;
  opacity: 1;

  transition:
    translate 750ms cubic-bezier(0.22, 1, 0.36, 1),
    opacity 750ms cubic-bezier(0.22, 1, 0.36, 1);
  /* 逐层延迟：原版 delay-300 / delay-400，这里拉成等差 */
  transition-delay: calc(300ms + var(--i) * 90ms);

  will-change: translate, opacity;
}

/*
 * 混合模式**逐层不同**（照原版：screen / normal / hard-light 交替）。
 * 全部用 screen 的话，亮的层会互相抵消、最后糊成一团渐变色 ——
 * 拉开之后才看得出是"一片一片错开的字"。
 */
.ph__layer:nth-child(2) {
  mix-blend-mode: normal;
}

/* 中间这层给一个**高光色**（照原版那层金黄）—— 五层全是同色系的暗调会缺层次，
   插一层亮的，重影立刻"立"起来 */
.ph__layer:nth-child(3) {
  color: hsl(calc(var(--h) + 46), 96%, 70%);
  mix-blend-mode: hard-light;
}

.ph__layer:nth-child(4) {
  mix-blend-mode: normal;
}

.ph__layer:nth-child(5) {
  mix-blend-mode: hard-light;
}

/* 首帧之前：**全部退回同一个起点**并下沉 24px —— 于是看着是"从一处散开" */
@starting-style {
  .ph__layer {
    translate: 0 24px;
    opacity: 0;
  }
}

/* ---------------------------- 下面一行小字 ---------------------------- */
.ph__meta {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: clamp(14px, 1.8vw, 22px);
  font-family: var(--mono);
  font-size: 13.5px;
  opacity: 1;
  translate: 0 0;
  transition:
    translate 750ms cubic-bezier(0.22, 1, 0.36, 1),
    opacity 750ms cubic-bezier(0.22, 1, 0.36, 1);
  transition-delay: 300ms;
}

@starting-style {
  .ph__meta {
    translate: 0 24px;
    opacity: 0;
  }
}

.ph__v {
  color: var(--text-dim);
  letter-spacing: 0.05em;
}

.ph__dot {
  color: var(--muted);
}

.ph__link {
  color: hsl(var(--h), 88%, 68%);
  border-bottom: 1px solid hsl(var(--h), 88%, 68%, 0.4);
  transition: border-color 0.3s var(--ease);
}

.ph__link:hover {
  border-color: hsl(var(--h), 88%, 68%);
}

/* 关掉动效的用户：不给过渡，直接就位 */
@media (prefers-reduced-motion: reduce) {
  .ph__name,
  .ph__meta {
    transition: none;
  }

  .ph__layer {
    transition: none;
    translate: calc(var(--i) * 0.12em) 0;
  }
}

@media (max-width: 640px) {
  /* 窄屏上错开减半，免得重影溢出屏幕 */
  .ph__layer {
    translate: calc(var(--i) * 0.07em) 0;
  }
}
</style>
