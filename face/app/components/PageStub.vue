<script setup>
/**
 * 占位页。
 *
 * 页脚目录里的博客 / 论坛 / 回测报告 / 文章都需要能点的链接，但这四块内容还没做，
 * 于是统一用这个组件兜底：说清"还在建设中"，并给一条回首页的路 —— 不留 404。
 * 真实内容做好后，把对应的 pages/*.vue 换成真内容即可，全站外壳（页头 / 页脚）不用动。
 */
defineProps({
  title: { type: String, required: true },
  note: { type: String, default: '' },
})
</script>

<template>
  <section class="section stub">
    <div class="container">
      <div class="stub__head" v-reveal="{ selector: '.rv', stagger: 0.1 }">
        <p class="eyebrow rv">{{ title }}</p>
        <h1 class="title rv">这个板块<em>还在建设中</em></h1>
        <p class="lead rv">{{ note }}</p>
      </div>

      <p class="stub__back">
        <a class="btn" href="/"><span>回到首页</span></a>
      </p>
    </div>
  </section>
</template>

<style scoped>
.stub__head {
  max-width: 640px;
}

.stub__back {
  margin-top: clamp(36px, 5vw, 56px);
}
</style>
