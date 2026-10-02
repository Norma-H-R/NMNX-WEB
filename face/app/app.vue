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
    页面骨架：这里只按顺序摆区块，不写任何区块内部的布局或样式。
    改某一块（文案 / 样式 / 交互）请直接改对应组件文件：
      HeroBlock.vue        首屏：点阵画布 + 标题文案 + 磨砂黑层
      AboutSection.vue     关于
      CapabilitySection.vue 能力
      ContactSection.vue   联系
      SiteHeader.vue       页头（含液态玻璃折射滤镜）
      SiteFooter.vue       页脚（含故障风字标）
    组件由 Nuxt 自动导入，不必在这里 import。
  -->
  <main>
    <HeroBlock />

    <AboutSection />
    <CapabilitySection />
    <ContactSection />
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
