<script setup>
/**
 * 首页 = 首屏 / 理念 / 能力 / 联系 四块。
 *
 * 加入 pages/ 之后 app.vue 只保留全站外壳（页头 / main / 页脚 / 噪点），
 * 页面内容挪到这里。同目录下还有四个占位页：
 * blog / forum / reports / articles（页脚目录指向它们）。
 */
</script>

<template>
  <HeroBlock />
  <AboutSection />
  <CapabilitySection />
  <ContactSection />
</template>
