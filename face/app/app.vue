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

  <!-- 背景层：点阵地形，在内容之下。
       原先的极光光晕（.aurora）按新设计去掉了，需要的话可以从 git 历史里找回。 -->
  <DotMatrix />

  <SiteHeader />

  <main>
    <HeroSection />
    <AboutSection />
    <CapabilitySection />
    <ContactSection />
  </main>

  <SiteFooter />

  <!-- 最上层质感噪点 -->
  <div class="grain" aria-hidden="true" />
</template>

<style scoped>
main {
  position: relative;
  z-index: 1;
}
</style>
