<script setup>
// 摘掉 SSR 首帧遮罩类（见 nuxt.config.ts 的说明）。
//
// 放在根组件里而不是 HeroSection 里，有两个原因：
//   1. 子组件的 onMounted 早于父组件执行，所以此刻首屏元素已经拿到 GSAP 写的
//      初始内联样式，摘类不会产生任何可见跳变；
//   2. 无论页面由哪些区块组成，这个类都一定会被清掉，不会残留把内容锁死。
onMounted(() => {
  document.documentElement.classList.remove('js-on')
})
</script>

<template>
  <NuxtRouteAnnouncer />

  <!-- 背景层：极光光晕 + 星场，都在内容之下 -->
  <div class="aurora" aria-hidden="true" />
  <StarField />

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
