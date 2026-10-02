<script setup>
/**
 * 首屏区块 = 点阵画布 + 首屏内容。
 *
 * 为什么单独成文件：点阵是**绝对定位的覆盖层**，尺寸必须与首屏区块完全一致
 * （对齐参考站实测结构：画布 1424x741 @ top 64 —— 它是 hero 的覆盖层，
 * 不是全屏 fixed 背景，会随页面一起滚走）。
 *
 * 所以「定位基准 + 画布 + 文案」三者必须待在同一个层叠上下文里：
 * 本组件持有 .hero-bg 这个定位基准，App.vue 那边只需摆一个 <HeroBlock />，
 * 不必再关心首屏的任何布局细节。
 */
</script>

<template>
  <div class="hero-bg">
    <DotMatrix />
    <HeroSection />
  </div>
</template>

<style scoped>
/* 点阵的定位基准：高度即 hero 高度 */
.hero-bg {
  position: relative;
  /* 独立层叠上下文：点阵与磨砂黑都只在这一块内部叠，不影响其它区块 */
  isolation: isolate;
}
</style>
