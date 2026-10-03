<script setup lang="ts">
import { computed, ref } from 'vue'
import { marked } from 'marked'
import { fetchAuthor, fetchBlog, fetchBlogs, type Blog, type BlogAttachment } from '~/composables/useBlog'
import { goWithVeil } from '~/composables/usePageLink'

/**
 * 博客详情（/blog/{slug}）—— 正文 + 附件 + 互动 + 评论区。
 *
 * ⚠️ **XSS**：这里用 `v-html` 吃 `marked` 的输出，**只对可信内容成立**。
 *    博客正文将来是用户写的 UGC —— 按文档（10-blog.md 的「XSS 底线」），
 *    正式环境必须**服务端渲染 + 白名单过滤**，前端只展示后端给的 `body_html`，
 *    绝不自己拿 `marked` 去渲染用户输入。现在这么写是因为跑的是本地假数据。
 */

const route = useRoute()
const slug = computed(() => String(route.params.slug))
const blog = computed(() => fetchBlog(slug.value))

/** 服务端渲染好之后，这里换成一字不改地用后端的 body_html */
const html = computed(() => {
  const b = blog.value
  if (!b) return ''

  return marked.parse(b.body, { async: false }) as string
})

/** 作者的公开资料 —— 左侧那张悬浮卡 */
const author = computed(() => {
  const b = blog.value

  return b ? fetchAuthor(b.author.id) : null
})

/** 相关推荐：同 tag 的前 3 篇（正式环境走 /blogs/{slug}/related） */
const related = computed(() => {
  const b = blog.value
  if (!b) return []

  return fetchBlogs()
    .filter((x) => x.id !== b.id && x.tags.some((t) => b.tags.includes(t)))
    .slice(0, 3)
})

const dateText = (iso: string) => iso.slice(0, 10)

/** 附件图标的字形（不引图标库，够用了） */
const ICON: Record<BlogAttachment['kind'], string> = {
  image: '🖼',
  audio: '♪',
  video: '▶',
  doc: '📄',
  sheet: '▦',
  pdf: '📕',
  other: '📎',
}

const sizeText = (n: number) => (n > 1_048_576 ? `${(n / 1_048_576).toFixed(1)} MB` : `${Math.round(n / 1024)} KB`)

const reacted = ref<{ liked: boolean, favorited: boolean, blocked: boolean }>({
  liked: false,
  favorited: false,
  blocked: false,
})

/** 乐观更新：先动本地；真实环境失败要回滚 */
function react(type: 'like' | 'favorite' | 'block') {
  const b = blog.value
  if (!b) return

  const key = type === 'like' ? 'liked' : type === 'favorite' ? 'favorited' : 'blocked'

  if (reacted.value[key]) {
    reacted.value[key] = false
    b.stats[type] -= 1

    return
  }

  reacted.value[key] = true
  b.stats[type] += 1
}

useHead(() => ({
  title: blog.value ? `${blog.value.title} · 博客` : '博客 · 南门拈星',
}))
</script>

<template>
  <section class="section">
    <div class="container">
      <BackFab fallback="/blog" label="全部博客" />

      <div v-if="blog" class="two">
        <!--
          左侧：作者悬浮卡。宽屏时 sticky 跟着滚，点进他的个人主页。
          （sticky 就是"悬浮"最省事也最稳的写法 —— 不用 fixed + 手算偏移）
        -->
        <aside class="side">
          <div v-if="author" class="who">
            <div class="who__banner" :class="{ 'is-empty': !author.banner }">
              <img v-if="author.banner" :src="author.banner" alt="" />
              <span v-else class="ph" aria-hidden="true" />
            </div>

            <span class="who__avatar" :style="{ '--h': author.hue }">
              {{ author.name.charAt(0) }}
            </span>

            <div class="who__body">
              <p class="who__name">{{ author.name }}</p>
              <p class="who__bio">{{ author.bio }}</p>

              <ul class="who__stats">
                <li><b>{{ author.stats.posts }}</b><span>篇</span></li>
                <li><b>{{ author.stats.like }}</b><span>赞</span></li>
                <li><b>{{ author.stats.view }}</b><span>阅读</span></li>
              </ul>

              <a
                class="who__go"
                :href="`/u/${author.id}`"
                @click="goWithVeil($event, `/u/${author.id}`)"
              >
                进入他的主页 ›
              </a>
            </div>
          </div>
        </aside>

        <!-- 右侧：正文那一条 -->
        <div class="main">
          <header class="head">
          <div class="head__tags">
            <span v-for="t in blog.tags" :key="t" class="tag">{{ t }}</span>
            <span v-if="blog.status === 'draft'" class="tag tag--draft">草稿</span>
          </div>

          <h1 class="title">{{ blog.title }}</h1>

          <div class="meta">
            <span class="who">{{ blog.author.name }}</span>
            <span class="dot">·</span>
            <span>{{ dateText(blog.publishedAt) }}</span>
            <span class="dot">·</span>
            <span>{{ blog.stats.view }} 阅读</span>
          </div>
        </header>

        <!-- 封面：没有就是占位块 -->
        <div class="cover" :class="{ 'is-empty': !blog.cover }">
          <img v-if="blog.cover" :src="blog.cover" alt="" />
          <span v-else class="ph" aria-hidden="true" />
        </div>

        <!-- 互动条 -->
        <div class="react">
          <button
            type="button"
            class="rbtn rbtn--like"
            :class="{ 'is-on': reacted.liked }"
            @click="react('like')"
          >
            <span class="rbtn__i">♥</span> 点赞 {{ blog.stats.like }}
          </button>
          <button
            type="button"
            class="rbtn rbtn--fav"
            :class="{ 'is-on': reacted.favorited }"
            @click="react('favorite')"
          >
            <span class="rbtn__i">★</span> 收藏 {{ blog.stats.favorite }}
          </button>
          <button
            type="button"
            class="rbtn rbtn--block"
            :class="{ 'is-on': reacted.blocked }"
            @click="react('block')"
          >
            <span class="rbtn__i">⊘</span> 不想再看 {{ blog.stats.block }}
          </button>
        </div>

        <!-- 正文：Markdown 渲染（XSS 说明见脚本顶部） -->
        <article class="prose" v-html="html" />

        <!-- 附件：按后端给的 kind 出不同卡片 -->
        <div v-if="blog.atts.length" class="atts">
          <h2 class="atts__title">附件 <span>{{ blog.atts.length }}</span></h2>

          <ul class="atts__list">
            <li v-for="a in blog.atts" :key="a.id" class="att" :class="`att--${a.kind}`">
              <!-- 图片：直接铺开 -->
              <template v-if="a.kind === 'image'">
                <img :src="a.url" :alt="a.name" loading="lazy" />
                <p class="att__name">{{ a.name }}</p>
              </template>

              <!-- 音视频：内嵌播放器 -->
              <template v-else-if="a.kind === 'audio' || a.kind === 'video'">
                <audio v-if="a.kind === 'audio'" class="att__player" controls :src="a.url" />
                <video v-else class="att__player" controls :src="a.url" />
                <p class="att__name">{{ a.name }}</p>
              </template>

              <!-- 文件：图标 + 名字 + 大小 + 下载 -->
              <template v-else>
                <div class="att__row">
                  <span class="att__icon" aria-hidden="true">{{ ICON[a.kind] }}</span>
                  <div class="att__info">
                    <p class="att__name">{{ a.name }}</p>
                    <p class="att__size">{{ a.kind.toUpperCase() }} · {{ sizeText(a.size) }}</p>
                  </div>
                  <a class="att__dl" :href="a.url" download>下载</a>
                </div>
              </template>
            </li>
          </ul>
        </div>

          <!-- 评论：插件，只认 targetType / targetId（论坛、文章将来插同一套） -->
          <CommentThread target-type="blog" :target-id="blog.id" />

          <!-- 相关推荐 -->
          <div v-if="related.length" class="rel">
            <h2 class="rel__title">相关</h2>
            <ul class="rel__list">
              <li v-for="r in related" :key="r.id">
                <a :href="`/blog/${r.slug}`" @click="goWithVeil($event, `/blog/${r.slug}`)">
                  {{ r.title }}
                </a>
              </li>
            </ul>
          </div>
        </div>
      </div>

      <template v-else>
        <p class="eyebrow">博客</p>
        <h1 class="title">没有找到<em>这篇博客</em></h1>
        <p class="lead">它可能已经被作者删除，或者链接拼错了。</p>

        <p class="acts">
          <a class="btn" href="/blog" @click="goWithVeil($event, '/blog')">
            <span>看看全部博客</span>
          </a>
        </p>
      </template>
    </div>
  </section>
</template>

<style scoped>
/* ---------------------------- 两栏骨架 ---------------------------- */
.two {
  display: grid;
  grid-template-columns: 264px minmax(0, 1fr);
  gap: clamp(20px, 2.6vw, 36px);
  align-items: start;
}

.side {
  /* 悬浮：跟着滚但停在视口里。sticky 比 fixed 省事，也不用算偏移 */
  position: sticky;
  top: 96px;
}

.main {
  min-width: 0;
  max-width: 780px;
}

/* ---------------------------- 作者卡 ---------------------------- */
.who {
  position: relative;
  border: 1px solid var(--line);
  border-radius: 16px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.01));
  overflow: hidden;
}

.who__banner {
  aspect-ratio: 16 / 7;
  background: #0b0f1a;
}

.who__banner img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.who__banner .ph {
  display: block;
  width: 100%;
  height: 100%;
  background-image: repeating-linear-gradient(
    -45deg,
    rgba(255, 255, 255, 0.05),
    rgba(255, 255, 255, 0.05) 8px,
    transparent 8px,
    transparent 16px
  );
}

.who__avatar {
  position: absolute;
  top: calc(16 / 7 * 264px / 4 - 22px);
  left: 16px;
  display: grid;
  place-items: center;
  width: 46px;
  height: 46px;
  border: 3px solid var(--bg);
  border-radius: 50%;
  background: linear-gradient(140deg, hsl(var(--h), 70%, 62%), hsl(calc(var(--h) + 40), 66%, 50%));
  color: #06070d;
  font-size: 18px;
  font-weight: 600;
}

.who__body {
  padding: 16px;
}

.who__name {
  font-size: 15px;
  font-weight: 500;
}

.who__bio {
  margin-top: 8px;
  font-size: 12.5px;
  line-height: 1.75;
  color: var(--muted);
}

.who__stats {
  display: flex;
  gap: 16px;
  margin: 14px 0 0;
  padding: 12px 0 0;
  border-top: 1px solid var(--line);
  list-style: none;
}

.who__stats li {
  display: flex;
  align-items: baseline;
  gap: 4px;
}

.who__stats b {
  font-family: var(--mono);
  font-size: 14px;
  font-weight: 500;
  color: var(--text);
}

.who__stats span {
  font-size: 11px;
  color: var(--muted);
}

.who__go {
  display: block;
  margin-top: 14px;
  padding: 9px 0;
  border: 1px solid rgba(110, 231, 255, 0.35);
  border-radius: 99px;
  text-align: center;
  font-size: 12.5px;
  color: var(--cyan);
  background: rgba(110, 231, 255, 0.07);
  transition: background 0.35s var(--ease), border-color 0.35s var(--ease);
}

.who__go:hover {
  background: rgba(110, 231, 255, 0.14);
  border-color: var(--cyan);
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

.head__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.tag {
  padding: 2px 8px;
  border: 1px solid var(--line);
  border-radius: 6px;
  font-size: 11px;
  letter-spacing: 0.06em;
  color: var(--muted);
}

.tag--draft {
  border-color: rgba(242, 209, 141, 0.4);
  color: var(--gold);
}

.title {
  margin-top: 14px;
}

.meta {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 12px;
  font-size: 12.5px;
  color: var(--muted);
}

.meta .who {
  color: var(--text-dim);
}

.meta .dot {
  opacity: 0.5;
}

/* ---------------------------- 封面 ---------------------------- */
.cover {
  margin-top: clamp(20px, 2.4vw, 30px);
  aspect-ratio: 16 / 9;
  border: 1px solid var(--line);
  border-radius: 14px;
  background: #0b0f1a;
  overflow: hidden;
}

.cover img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.cover .ph {
  display: block;
  width: 100%;
  height: 100%;
  background-image: repeating-linear-gradient(
    -45deg,
    rgba(255, 255, 255, 0.045),
    rgba(255, 255, 255, 0.045) 10px,
    transparent 10px,
    transparent 20px
  );
}

/* ---------------------------- 互动条 ---------------------------- */
.react {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 16px;
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

.rbtn--like.is-on {
  color: var(--cyan);
  border-color: rgba(110, 231, 255, 0.5);
  background: rgba(110, 231, 255, 0.1);
}

.rbtn--fav.is-on {
  color: var(--gold);
  border-color: rgba(242, 209, 141, 0.5);
  background: rgba(242, 209, 141, 0.1);
}

.rbtn--block.is-on {
  color: #ff9b9b;
  border-color: rgba(255, 155, 155, 0.45);
  background: rgba(255, 155, 155, 0.1);
}

/* ---------------------------- 正文 ---------------------------- */
.prose {
  margin-top: clamp(26px, 3vw, 40px);
  font-size: 15.5px;
  line-height: 2;
  color: var(--text-dim);
}

.prose :deep(h2) {
  margin: 34px 0 12px;
  font-size: 19px;
  font-weight: 500;
  color: var(--text);
}

.prose :deep(p) {
  margin: 14px 0;
}

.prose :deep(strong) {
  color: var(--text);
  font-weight: 500;
}

.prose :deep(a) {
  color: var(--cyan);
  border-bottom: 1px solid rgba(110, 231, 255, 0.35);
}

.prose :deep(ul),
.prose :deep(ol) {
  margin: 14px 0;
  padding-left: 22px;
}

.prose :deep(li) {
  margin: 6px 0;
}

.prose :deep(blockquote) {
  margin: 20px 0;
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
  margin: 20px 0;
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

.prose :deep(img) {
  margin: 20px 0;
  border: 1px solid var(--line);
  border-radius: 12px;
}

.prose :deep(table) {
  width: 100%;
  margin: 20px 0;
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

/* ---------------------------- 附件 ---------------------------- */
.atts {
  margin-top: clamp(30px, 3.4vw, 44px);
  padding-top: clamp(22px, 2.4vw, 30px);
  border-top: 1px solid var(--line);
}

.atts__title {
  font-size: 13px;
  font-weight: 500;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  color: var(--muted);
}

.atts__title span {
  margin-left: 6px;
  font-family: var(--mono);
  letter-spacing: 0;
  color: var(--text-dim);
}

.atts__list {
  display: grid;
  gap: 10px;
  margin: 16px 0 0;
  padding: 0;
  list-style: none;
}

.att {
  padding: 12px 14px;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.022);
}

.att--image {
  padding: 0;
  border: 0;
  background: transparent;
}

.att--image img {
  display: block;
  width: 100%;
  border: 1px solid var(--line);
  border-radius: 12px;
}

.att__player {
  display: block;
  width: 100%;
  border-radius: 10px;
}

.att__row {
  display: flex;
  align-items: center;
  gap: 12px;
}

.att__icon {
  flex: none;
  display: grid;
  place-items: center;
  width: 34px;
  height: 34px;
  border: 1px solid var(--line);
  border-radius: 9px;
  font-size: 15px;
}

.att__info {
  flex: 1;
  min-width: 0;
}

.att__name {
  margin-top: 8px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 13px;
  color: var(--text);
}

.att__row .att__name {
  margin-top: 0;
}

.att__size {
  margin-top: 3px;
  font-family: var(--mono);
  font-size: 11.5px;
  color: var(--muted);
}

.att__dl {
  flex: none;
  padding: 6px 14px;
  border: 1px solid var(--line-strong);
  border-radius: 99px;
  font-size: 12.5px;
  color: var(--text-dim);
  transition: color 0.3s var(--ease), border-color 0.3s var(--ease);
}

.att__dl:hover {
  color: var(--cyan);
  border-color: var(--cyan);
}

/* ---------------------------- 相关 ---------------------------- */
.rel {
  margin-top: clamp(30px, 3.4vw, 44px);
  padding-top: clamp(20px, 2.2vw, 28px);
  border-top: 1px solid var(--line);
}

.rel__title {
  font-size: 13px;
  font-weight: 500;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  color: var(--muted);
}

.rel__list {
  margin: 14px 0 0;
  padding: 0;
  list-style: none;
}

.rel__list li + li {
  margin-top: 9px;
}

.rel__list a {
  font-size: 14px;
  color: var(--text-dim);
  transition: color 0.3s var(--ease);
}

.rel__list a:hover {
  color: var(--cyan);
}

.acts {
  margin-top: clamp(26px, 2.8vw, 38px);
}

@media (prefers-reduced-motion: reduce) {
  .back,
  .rbtn,
  .att__dl,
  .rel__list a {
    transition: none;
  }
}
</style>
