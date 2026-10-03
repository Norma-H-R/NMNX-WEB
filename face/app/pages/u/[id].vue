<script setup lang="ts">
import { computed } from 'vue'
import { fetchAuthor, fetchAuthorBlogs } from '~/composables/useBlog'
import { goWithVeil } from '~/composables/usePageLink'

/**
 * 作者的个人主页（/u/{id}）—— 也就是他的**博客主页**。
 *
 * 这是"装修"的落点：作者将来能自己换主题色、换头图、写简介、置顶几篇。
 * 本期只把**数据形状**和布局定下来，装修编辑器是后面的事：
 *   · `hue`     主题色相   → 换一个数，整页的点缀色跟着变
 *   · `banner`  头图       → 没上传就是占位块（同封面那套规则）
 *   · `bio`     简介
 *   · `decorated` 是否装修过 —— 没装修的走默认样式
 *
 * 数据走 useBlog 的假数据；接口是 GET /api/v1/public/authors/{id}。
 */

const route = useRoute()

const author = computed(() => fetchAuthor(Number(route.params.id)))
const blogs = computed(() => (author.value ? fetchAuthorBlogs(author.value.id) : []))

useHead(() => ({
  title: author.value ? `${author.value.name} 的主页 · 博客` : '作者主页 · 南门拈星',
}))

const dateText = (iso: string) => iso.slice(0, 10)
</script>

<template>
  <section class="section">
    <BackFab fallback="/blog" label="公共博客" />

    <div class="container">
      <template v-if="author">
        <!-- 头图 + 身份 -->
        <div class="hero" :style="{ '--h': author.hue }">
          <div class="hero__banner" :class="{ 'is-empty': !author.banner }">
            <img v-if="author.banner" :src="author.banner" alt="" />
            <span v-else class="ph" aria-hidden="true" />
          </div>

          <div class="hero__bar">
            <span class="hero__avatar">{{ author.name.charAt(0) }}</span>

            <div class="hero__id">
              <h1 class="hero__name">{{ author.name }}</h1>
              <p class="hero__bio">{{ author.bio }}</p>
            </div>

            <span v-if="author.decorated" class="hero__tag">已装修</span>
          </div>
        </div>

        <!-- 数据 -->
        <ul class="stats">
          <li>
            <span class="stats__k">博客</span>
            <span class="stats__v">{{ author.stats.posts }}</span>
          </li>
          <li class="stats__i--like">
            <span class="stats__k">总点赞</span>
            <span class="stats__v">{{ author.stats.like }}</span>
          </li>
          <li class="stats__i--fav">
            <span class="stats__k">总收藏</span>
            <span class="stats__v">{{ author.stats.favorite }}</span>
          </li>
          <li>
            <span class="stats__k">总阅读</span>
            <span class="stats__v">{{ author.stats.view }}</span>
          </li>
        </ul>

        <!-- 他写的博客 -->
        <h2 class="sec">他的博客</h2>

        <ul class="posts">
          <li v-for="b in blogs" :key="b.id" class="post">
            <a
              class="post__cover"
              :class="{ 'is-empty': !b.cover }"
              :href="`/blog/${b.slug}`"
              @click="goWithVeil($event, `/blog/${b.slug}`)"
            >
              <img v-if="b.cover" :src="b.cover" alt="" loading="lazy" />
              <span v-else class="ph" aria-hidden="true" />
            </a>

            <div class="post__body">
              <a
                class="post__title"
                :href="`/blog/${b.slug}`"
                @click="goWithVeil($event, `/blog/${b.slug}`)"
              >
                {{ b.title }}
              </a>
              <p class="post__ex">{{ b.excerpt }}</p>
              <p class="post__meta">
                {{ dateText(b.publishedAt) }} · {{ b.stats.view }} 阅读 ·
                ♥ {{ b.stats.like }} · ★ {{ b.stats.favorite }} · {{ b.stats.comment }} 条评论
              </p>
            </div>
          </li>
        </ul>

        <p v-if="!blogs.length" class="empty">他还没有发布过博客。</p>

        <p class="back">
          <a class="btn" href="/blog" @click="goWithVeil($event, '/blog')">
            <span>回到公共博客</span>
          </a>
        </p>
      </template>

      <template v-else>
        <p class="eyebrow">作者</p>
        <h1 class="title">没有找到<em>这位作者</em></h1>
        <p class="lead">链接可能拼错了。</p>

        <p class="back">
          <a class="btn" href="/blog" @click="goWithVeil($event, '/blog')">
            <span>回到公共博客</span>
          </a>
        </p>
      </template>
    </div>
  </section>
</template>

<style scoped>
/* ---------------------------- 头图 ---------------------------- */
.hero {
  border: 1px solid var(--line);
  border-radius: 18px;
  overflow: hidden;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.01));
}

.hero__banner {
  aspect-ratio: 16 / 4;
  background: #0b0f1a;
}

.hero__banner img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

/* 头图颜色带一点作者的主题色，好让"装修"看得出来 */
.hero__banner.is-empty .ph {
  display: block;
  width: 100%;
  height: 100%;
  background:
    linear-gradient(
      120deg,
      hsl(var(--h), 60%, 40%, 0.45),
      transparent 60%
    ),
    repeating-linear-gradient(
      -45deg,
      rgba(255, 255, 255, 0.045),
      rgba(255, 255, 255, 0.045) 10px,
      transparent 10px,
      transparent 20px
    );
}

.hero__bar {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 14px 20px 18px;
}

.hero__avatar {
  flex: none;
  display: grid;
  place-items: center;
  width: 62px;
  height: 62px;
  margin-top: -40px;
  border: 3px solid var(--bg);
  border-radius: 50%;
  background: linear-gradient(140deg, hsl(var(--h), 70%, 62%), hsl(calc(var(--h) + 40), 66%, 50%));
  color: #06070d;
  font-size: 24px;
  font-weight: 600;
}

.hero__id {
  flex: 1;
  min-width: 0;
}

.hero__name {
  font-size: clamp(19px, 2.2vw, 24px);
  font-weight: 500;
}

.hero__bio {
  margin-top: 6px;
  font-size: 13px;
  color: var(--muted);
}

.hero__tag {
  flex: none;
  padding: 3px 11px;
  border: 1px solid hsl(var(--h), 70%, 60%, 0.4);
  border-radius: 99px;
  font-size: 11px;
  letter-spacing: 0.08em;
  color: hsl(var(--h), 80%, 72%);
}

/* ---------------------------- 数据 ---------------------------- */
.stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
  gap: clamp(10px, 1.2vw, 14px);
  margin: clamp(18px, 2vw, 26px) 0 0;
  padding: 0;
  list-style: none;
}

.stats li {
  display: grid;
  gap: 6px;
  padding: 13px 15px;
  border: 1px solid var(--line);
  border-radius: 14px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.01));
}

.stats__k {
  font-size: 11.5px;
  letter-spacing: 0.14em;
  color: var(--muted);
}

.stats__v {
  font-family: var(--mono);
  font-size: 21px;
  font-weight: 500;
  font-variant-numeric: tabular-nums;
}

.stats__i--like .stats__v {
  color: var(--cyan);
}

.stats__i--fav .stats__v {
  color: var(--gold);
}

/* ---------------------------- 博客列表 ---------------------------- */
.sec {
  margin-top: clamp(26px, 3vw, 38px);
  padding-bottom: 12px;
  border-bottom: 1px solid var(--line);
  font-size: 13px;
  font-weight: 500;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  color: var(--muted);
}

.posts {
  display: grid;
  gap: 12px;
  margin: 16px 0 0;
  padding: 0;
  list-style: none;
}

.post {
  display: flex;
  gap: 14px;
  padding: 12px;
  border: 1px solid var(--line);
  border-radius: 14px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.03), rgba(255, 255, 255, 0.008));
  transition: border-color 0.4s var(--ease);
}

.post:hover {
  border-color: var(--line-strong);
}

.post__cover {
  flex: none;
  display: block;
  width: 150px;
  aspect-ratio: 16 / 9;
  border-radius: 10px;
  background: #0b0f1a;
  overflow: hidden;
}

.post__cover img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.post__cover .ph {
  display: block;
  width: 100%;
  height: 100%;
  background-image: repeating-linear-gradient(
    -45deg,
    rgba(255, 255, 255, 0.045),
    rgba(255, 255, 255, 0.045) 8px,
    transparent 8px,
    transparent 16px
  );
}

.post__body {
  flex: 1;
  min-width: 0;
}

.post__title {
  display: block;
  font-size: 15px;
  transition: color 0.3s var(--ease);
}

.post__title:hover {
  color: var(--cyan);
}

.post__ex {
  margin-top: 7px;
  font-size: 12.5px;
  line-height: 1.7;
  color: var(--muted);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.post__meta {
  margin-top: 9px;
  font-family: var(--mono);
  font-size: 11.5px;
  color: var(--muted);
}

.empty {
  padding: 26px 0;
  text-align: center;
  font-size: 13px;
  color: var(--muted);
}

.back {
  margin-top: clamp(24px, 2.6vw, 34px);
}

@media (max-width: 640px) {
  .post {
    flex-direction: column;
  }

  .post__cover {
    width: 100%;
  }
}

@media (prefers-reduced-motion: reduce) {
  .post,
  .post__title {
    transition: none;
  }
}
</style>
