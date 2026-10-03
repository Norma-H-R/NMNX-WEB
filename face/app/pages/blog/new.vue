<script setup lang="ts">
import { goWithVeil } from '~/composables/usePageLink'

/**
 * 新建博客（/blog/new）。
 *
 * 正式环境要登录（`auth:member`），未登录应走闸门过渡回 `/account`。
 * 编辑器本体在 `BlogEditor.vue`，与编辑页共用。
 */

useHead({ title: '写一篇 · 博客' })
</script>

<template>
  <section class="section">
    <div class="container narrow">
      <BackFab fallback="/blog/mine" label="我的博客" />

      <p class="eyebrow">新建</p>
      <h1 class="title">写一篇<em>博客</em></h1>
      <p class="lead">正文是 Markdown，保存的是源文本 —— 以后想改格式，随时能改回来。</p>

      <BlogEditor />
    </div>
  </section>
</template>

<style scoped>
.narrow {
  max-width: 860px;
}

.back {
  display: inline-block;
  margin-bottom: clamp(18px, 2vw, 26px);
  font-size: 13px;
  letter-spacing: 0.06em;
  color: var(--muted);
  transition: color 0.35s var(--ease);
}

.back:hover {
  color: var(--cyan);
}
</style>
