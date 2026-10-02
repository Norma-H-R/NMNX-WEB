<script setup>
// 摘掉 SSR 首帧遮罩类（见 nuxt.config.ts 的说明）。
//
// 放在根组件里而不是某个区块里，有两个原因：
//   1. 子组件的 onMounted 早于父组件执行，所以此刻 v-reveal 指令已经给滚动入场的
//      元素挂好了 data-reveal，摘类不会产生任何可见跳变；
//   2. 无论页面由哪些区块组成，这个类都一定会被清掉，不会残留把内容锁死。
//
// 注意：这个类只管滚动入场的元素。首屏 Hero 的入场动画是纯 CSS 的，
// 靠 animation-fill-mode: backwards 在 delay 期间就保持起始态，不依赖遮罩。
onMounted(() => {
  document.documentElement.classList.remove('js-on')
})
</script>

<template>
  <NuxtRouteAnnouncer />

  <SiteHeader />

  <!--
    全站外壳：页头 / main / 页脚 / 噪点都留在这一层，换路由时不重挂。
    各页自己的内容在 pages/ 下：
      pages/index.vue      首页：首屏 + 理念 + 能力 + 联系
      pages/blog.vue       博客（占位）
      pages/forum.vue      论坛（占位）
      pages/reports.vue    回测报告（占位）
      pages/articles.vue   文章（占位）
    区块组件由 Nuxt 自动导入，不必在这里 import。
  -->
  <main>
    <NuxtPage />
  </main>

  <SiteFooter />

  <!-- 最上层质感噪点 -->
  <div class="grain" aria-hidden="true" />
</template>

<style scoped>
/* 骨架层自己的定位：页面内容整体压在点阵/噪点之上 */
main {
  position: relative;
  z-index: 1;
}
</style>
