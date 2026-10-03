<script setup lang="ts">
import { computed } from 'vue'
import { fetchBlogs } from '~/composables/useBlog'
import { goWithVeil } from '~/composables/usePageLink'

/**
 * 编辑已有博客（/blog/edit/{id}）。
 *
 * 与新建共用 `BlogEditor.vue`；这里只负责把已有内容取出来塞进去。
 * 正式环境：PUT /api/v1/member/blogs/{id}，且**仅作者本人**能改。
 *
 * 用 id（不是 slug）作为编辑入口是刻意的：slug 发布后就锁死了，
 * 拿它当编辑主键会在改名场景下绕远路。
 */

const route = useRoute()

const blog = computed(() => {
  const id = Number(route.params.id)

  return fetchBlogs().find((b) => b.id === id) ?? null
})

useHead(() => ({
  title: blog.value ? `编辑 · ${blog.value.title}` : '编辑 · 博客',
}))
</script>

<template>
  <section class="section">
    <div class="container narrow">
      <BackFab fallback="/blog/mine" label="我的博客" />

      <template v-if="blog">
        <p class="eyebrow">编辑</p>
        <h1 class="title">修改<em>这篇博客</em></h1>
        <p class="lead">
          正在编辑 <code class="code">{{ blog.slug }}</code> —— 别名发布后不再变动，改了旧链接就断了。
        </p>

        <BlogEditor :blog="blog" />
      </template>

      <template v-else>
        <p class="eyebrow">编辑</p>
        <h1 class="title">没有找到<em>这篇博客</em></h1>
        <p class="lead">它可能已经被删除了。</p>

        <p class="acts">
          <a class="btn" href="/blog/mine" @click="goWithVeil($event, '/blog/mine')">
            <span>回到我的博客</span>
          </a>
        </p>
      </template>
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

.code {
  padding: 2px 7px;
  border-radius: 6px;
  background: rgba(255, 255, 255, 0.06);
  font-family: var(--mono);
  font-size: 12.5px;
  color: var(--cyan);
}

.acts {
  margin-top: clamp(26px, 2.8vw, 38px);
}
</style>
