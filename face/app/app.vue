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

  <!--
    换页闸门（"大刷屏"）。
    与 admin 的 PageVeil 是同一套：由 usePageVeil 的 transitionTo() 驱动。
    必须挂在路由出口之外 —— 它要活得比任何单个页面久，
    挂在页面里的话，页面一卸载遮罩跟着消失，过渡就断在半截。
  -->
  <PageVeil />

  <!--
    系统公告的默认弹窗。同样挂在外壳上：任何一页进来，只要还有未读公告都会弹。
    组件自己判断该不该显示（要等读完 localStorage 才决定），所以这里无条件挂载。
  -->
  <NoticeModal />

  <!--
    流光通知条：右侧滑入的那条。与弹窗共用同一份已读状态，
    所以关掉哪一边，另一边也不会再提示同一条。
  -->
  <NoticeStack />

  <!--
    通知调试面板 —— **临时件**。验收完删掉本行 + NoticeDebug.vue 就行，别处不用动。
    （做在官网这边而不是 admin：要预览的就是这些官网通知组件，
      admin 是独立项目，共享不到这些文件。）
  -->
  <NoticeDebug />
</template>

<style scoped>
/* 骨架层自己的定位：页面内容整体压在点阵/噪点之上 */
main {
  position: relative;
  z-index: 1;
}
</style>
