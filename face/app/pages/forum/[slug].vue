<script setup lang="ts">
import { computed, ref } from 'vue'
import { marked } from 'marked'
import { boardName, boardSlug, fetchPost, fetchPosts } from '~/composables/useForum'
import { goWithVeil } from '~/composables/usePageLink'

/**
 * 帖子详情（/forum/{slug}）。
 *
 * ⚠️ 和博客详情的关键区别：**论坛详情页不做静态预渲染**（见 02-content.md 的缓存策略）——
 *    论坛高频写入，每条回复都重新生成 HTML 会把构建撑爆。生产环境这里应该是
 *    客户端拉数据 + 短 TTL。
 *
 * 评论区**直接复用同一套插件**，只把 `targetType` 换成 `forum_post` —— 一行代码的差别。
 */

const route = useRoute()
const slug = computed(() => String(route.params.slug))
const post = computed(() => fetchPost(slug.value))

const html = computed(() => {
  const p = post.value
  if (!p) return ''

  return marked.parse(p.body, { async: false }) as string
})

/** 同版块的其它帖子 */
const siblings = computed(() => {
  const p = post.value
  if (!p) return []

  return fetchPosts(boardSlug(p.boardId)).filter((x) => x.id !== p.id).slice(0, 5)
})

const liked = ref(false)
const favorited = ref(false)

const dateText = (iso: string) => iso.slice(0, 10)

useHead(() => ({
  title: post.value ? `${post.value.title} · 论坛` : '论坛 · 南门拈星',
}))
</script>

<template>
  <section class="section">
    <BackFab fallback="/forum" label="返回论坛" />

    <div class="container">
      <template v-if="post">
        <nav class="crumb">
          <a href="/forum" @click="goWithVeil($event, '/forum')">论坛</a>
          <span class="crumb__sep">/</span>
          <a
            :href="`/forum?board=${boardSlug(post.boardId)}`"
            @click="goWithVeil($event, `/forum?board=${boardSlug(post.boardId)}`)"
          >
            {{ boardName(post.boardId) }}
          </a>
        </nav>

        <header class="head">
          <h1 class="title">
            <span v-if="post.pin" class="pin">置顶</span>
            {{ post.title }}
          </h1>

          <div class="meta">
            <span class="avatar">{{ post.author.name.charAt(0) }}</span>
            <span class="meta__who">{{ post.author.name }}</span>
            <span class="dot">·</span>
            <span>{{ dateText(post.createdAt) }}</span>
            <span class="dot">·</span>
            <span>{{ post.views }} 浏览</span>
            <span class="dot">·</span>
            <span>{{ post.commentCount }} 回复</span>
          </div>
        </header>

        <div class="react">
          <button type="button" class="rbtn" :class="{ 'is-on': liked }" @click="liked = !liked">
            <span class="rbtn__i">♥</span> 有用 {{ post.likes + (liked ? 1 : 0) }}
          </button>
          <button
            type="button"
            class="rbtn rbtn--fav"
            :class="{ 'is-on': favorited }"
            @click="favorited = !favorited"
          >
            <span class="rbtn__i">★</span> 收藏
          </button>
        </div>

        <article class="prose" v-html="html" />

        <!-- 评论区：同一套插件，只换 targetType -->
        <CommentThread target-type="forum_post" :target-id="post.id" />

        <div v-if="siblings.length" class="sib">
          <h2 class="sib__title">同版其它帖子</h2>
          <ul class="sib__list">
            <li v-for="s in siblings" :key="s.id">
              <a :href="`/forum/${s.slug}`" @click="goWithVeil($event, `/forum/${s.slug}`)">
                {{ s.title }}
              </a>
              <span class="sib__n">{{ s.commentCount }} 回复</span>
            </li>
          </ul>
        </div>
      </template>

      <template v-else>
        <p class="eyebrow">论坛</p>
        <h1 class="title">没有找到<em>这个帖子</em></h1>
        <p class="lead">它可能已经被删除了，或者链接拼错了。</p>

        <p class="acts">
          <a class="btn" href="/forum" @click="goWithVeil($event, '/forum')">
            <span>回到论坛</span>
          </a>
        </p>
      </template>
    </div>
  </section>
</template>

<style scoped>
.crumb {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 14px;
  font-size: 12.5px;
  color: var(--muted);
}

.crumb a {
  transition: color 0.3s var(--ease);
}

.crumb a:hover {
  color: var(--cyan);
}

.crumb__sep {
  opacity: 0.5;
}

.title {
  display: flex;
  align-items: center;
  gap: 12px;
}

.pin {
  flex: none;
  padding: 3px 10px;
  border: 1px solid rgba(242, 209, 141, 0.45);
  border-radius: 6px;
  font-size: 11.5px;
  font-weight: 400;
  color: var(--gold);
}

.meta {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 14px;
  font-size: 12.5px;
  color: var(--muted);
}

.avatar {
  display: grid;
  place-items: center;
  width: 22px;
  height: 22px;
  border-radius: 7px;
  background: rgba(255, 255, 255, 0.06);
  color: var(--text-dim);
  font-size: 11px;
}

.meta__who {
  color: var(--text-dim);
}

.dot {
  opacity: 0.5;
}

.react {
  display: flex;
  gap: 8px;
  margin-top: 18px;
}

.rbtn {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 8px 16px;
  border: 1px solid var(--line-strong);
  border-radius: 99px;
  background: transparent;
  color: var(--text-dim);
  font: inherit;
  font-size: 13px;
  font-variant-numeric: tabular-nums;
  cursor: pointer;
  transition: color 0.3s var(--ease), border-color 0.3s var(--ease), background 0.3s var(--ease);
}

.rbtn__i {
  font-size: 14px;
  line-height: 1;
}

.rbtn:hover {
  color: var(--text);
  border-color: var(--cyan);
}

.rbtn.is-on {
  color: var(--cyan);
  border-color: rgba(110, 231, 255, 0.5);
  background: rgba(110, 231, 255, 0.1);
}

.rbtn--fav.is-on {
  color: var(--gold);
  border-color: rgba(242, 209, 141, 0.5);
  background: rgba(242, 209, 141, 0.1);
}

.prose {
  max-width: 780px;
  margin-top: clamp(22px, 2.6vw, 32px);
  font-size: 15px;
  line-height: 1.95;
  color: var(--text-dim);
}

.prose :deep(h2) {
  margin: 30px 0 12px;
  font-size: 18px;
  color: var(--text);
}

.prose :deep(p) {
  margin: 13px 0;
}

.prose :deep(ul),
.prose :deep(ol) {
  margin: 13px 0;
  padding-left: 22px;
}

.prose :deep(li) {
  margin: 6px 0;
}

.prose :deep(blockquote) {
  margin: 18px 0;
  padding: 12px 18px;
  border-left: 2px solid var(--cyan);
  border-radius: 0 10px 10px 0;
  background: rgba(110, 231, 255, 0.05);
  color: var(--text);
}

.prose :deep(blockquote p) {
  margin: 0;
}

.prose :deep(code) {
  padding: 2px 6px;
  border-radius: 5px;
  background: rgba(255, 255, 255, 0.07);
  font-family: var(--mono);
  font-size: 13px;
  color: var(--cyan);
}

.prose :deep(pre) {
  margin: 18px 0;
  padding: 14px 16px;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: rgba(0, 0, 0, 0.35);
  overflow-x: auto;
}

.prose :deep(pre code) {
  padding: 0;
  background: transparent;
  color: var(--text-dim);
}

.prose :deep(table) {
  width: 100%;
  margin: 18px 0;
  border-collapse: collapse;
  font-size: 13.5px;
}

.prose :deep(th),
.prose :deep(td) {
  padding: 9px 12px;
  border: 1px solid var(--line);
  text-align: left;
}

.prose :deep(th) {
  color: var(--text);
  background: rgba(255, 255, 255, 0.04);
}

.sib {
  margin-top: clamp(30px, 3.4vw, 44px);
  padding-top: clamp(20px, 2.2vw, 28px);
  border-top: 1px solid var(--line);
}

.sib__title {
  font-size: 13px;
  font-weight: 500;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  color: var(--muted);
}

.sib__list {
  margin: 14px 0 0;
  padding: 0;
  list-style: none;
}

.sib__list li {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 14px;
  padding: 9px 0;
}

.sib__list a {
  font-size: 14px;
  color: var(--text-dim);
  transition: color 0.3s var(--ease);
}

.sib__list a:hover {
  color: var(--cyan);
}

.sib__n {
  flex: none;
  font-family: var(--mono);
  font-size: 11.5px;
  color: var(--muted);
}

.acts {
  margin-top: clamp(26px, 2.8vw, 38px);
}
</style>
