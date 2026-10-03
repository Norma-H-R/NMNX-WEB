<script setup lang="ts">
import { computed, ref } from 'vue'
import { fetchBlogs, type Blog } from '~/composables/useBlog'
import { goWithVeil } from '~/composables/usePageLink'

/**
 * 公共博客列表（/blog）—— 任何人的博客都在这里，未登录也能看。
 *
 * 卡片上**就地**能点赞 / 收藏 / 拉黑（用户要的"短暂地看"），不用点进详情。
 * 点击标题才进详情页，走闸门过渡。
 *
 * 数据来自 useBlog 的假数据；接口好了换那一层就行，这个文件不用动。
 */

const blogs = ref<Blog[]>(fetchBlogs())

/** 排序：最新 / 最热 */
const sort = ref<'new' | 'hot'>('new')

const list = computed(() =>
  sort.value === 'hot'
    ? [...blogs.value].sort((a, b) => b.stats.like + b.stats.favorite - (a.stats.like + a.stats.favorite))
    : blogs.value,
)

/** 就地互动：先乐观改本地数字；真实环境里失败要回滚（见文档待办） */
function react(b: Blog, type: 'like' | 'favorite' | 'block') {
  const key = type === 'like' ? 'liked' : type === 'favorite' ? 'favorited' : 'blocked'

  if (b.mine[key]) {
    b.mine[key] = false
    b.stats[type] -= 1

    return
  }

  b.mine[key] = true
  b.stats[type] += 1
}

/** 日期只取 YYYY-MM-DD。用 UTC 拼串而不是 toLocaleDateString ——
 *  后者跟运行环境的语言/时区有关，SSR 与浏览器不一致就会 hydration 报错 */
const dateText = (iso: string) => iso.slice(0, 10)

const totalComments = computed(() => blogs.value.reduce((s, b) => s + b.stats.comment, 0))
</script>

<template>
  <section class="section">
    <div class="container">
      <header class="head" v-reveal="{ selector: '.rv', stagger: 0.1 }">
        <p class="eyebrow rv">博客</p>
        <h1 class="title rv">会员<em>博客</em></h1>
        <p class="lead rv">
          大家写的实测、踩坑与复盘。点标题看全文，卡片上可以直接点赞、收藏或者标记"不想再看"。
        </p>
      </header>

      <div class="bar rv" v-reveal>
        <div class="tabs">
          <button
            type="button"
            class="tab"
            :class="{ 'is-on': sort === 'new' }"
            @click="sort = 'new'"
          >
            最新
          </button>
          <button
            type="button"
            class="tab"
            :class="{ 'is-on': sort === 'hot' }"
            @click="sort = 'hot'"
          >
            最热
          </button>
        </div>

        <div class="tools">
          <span class="count">{{ list.length }} 篇 · {{ totalComments }} 条评论</span>
          <a class="mini" href="/blog/mine" @click="goWithVeil($event, '/blog/mine')">我的博客</a>
          <a class="mini mini--key" href="/blog/new" @click="goWithVeil($event, '/blog/new')">写一篇</a>
        </div>
      </div>

      <ul class="cards">
        <li v-for="b in list" :key="b.id" class="card">
          <!-- 封面：没上传时渲染占位块，不留空洞、也不撑歪网格（决策 8） -->
          <a
            class="card__cover"
            :class="{ 'is-empty': !b.cover }"
            :href="`/blog/${b.slug}`"
            @click="goWithVeil($event, `/blog/${b.slug}`)"
          >
            <img v-if="b.cover" :src="b.cover" alt="" loading="lazy" />
            <span v-else class="ph" aria-hidden="true" />
          </a>

          <div class="card__body">
            <div class="card__tags">
              <span v-for="t in b.tags" :key="t" class="tag">{{ t }}</span>
              <span v-if="b.status === 'draft'" class="tag tag--draft">草稿</span>
            </div>

            <h2 class="card__title">
              <a :href="`/blog/${b.slug}`" @click="goWithVeil($event, `/blog/${b.slug}`)">
                {{ b.title }}
              </a>
            </h2>

            <p class="card__excerpt">{{ b.excerpt }}</p>

            <div class="card__meta">
              <span class="who">{{ b.author.name }}</span>
              <span class="dot">·</span>
              <span class="when">{{ dateText(b.publishedAt) }}</span>
              <span class="dot">·</span>
              <span class="when">{{ b.stats.view }} 阅读</span>
            </div>
          </div>

          <!-- 就地互动 -->
          <div class="card__foot">
            <button
              type="button"
              class="act act--like"
              :class="{ 'is-on': b.mine.liked }"
              @click="react(b, 'like')"
            >
              <span class="act__i">♥</span>{{ b.stats.like }}
            </button>
            <button
              type="button"
              class="act act--fav"
              :class="{ 'is-on': b.mine.favorited }"
              @click="react(b, 'favorite')"
            >
              <span class="act__i">★</span>{{ b.stats.favorite }}
            </button>
            <button
              type="button"
              class="act act--block"
              :class="{ 'is-on': b.mine.blocked }"
              @click="react(b, 'block')"
            >
              <span class="act__i">⊘</span>{{ b.stats.block }}
            </button>

            <a class="act act--go" :href="`/blog/${b.slug}`" @click="goWithVeil($event, `/blog/${b.slug}`)">
              {{ b.stats.comment }} 条评论 ›
            </a>
          </div>
        </li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
.head {
  max-width: 640px;
}

/* ---------------------------- 顶部条 ---------------------------- */
.bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 14px;
  margin-top: clamp(22px, 2.4vw, 32px);
}

.tabs {
  display: inline-flex;
  gap: 4px;
  padding: 4px;
  border: 1px solid var(--line);
  border-radius: 99px;
  background: rgba(255, 255, 255, 0.03);
}

.tab {
  padding: 7px 20px;
  border: 0;
  border-radius: 99px;
  background: transparent;
  color: var(--muted);
  font: inherit;
  font-size: 13px;
  letter-spacing: 0.08em;
  cursor: pointer;
  transition: color 0.35s var(--ease), background 0.45s var(--ease);
}

.tab:hover {
  color: var(--text);
}

.tab.is-on {
  color: #06070d;
  background: var(--grad);
}

.tools {
  display: flex;
  align-items: center;
  gap: 10px;
}

.count {
  font-family: var(--mono);
  font-size: 12.5px;
  color: var(--muted);
}

.mini {
  padding: 7px 15px;
  border: 1px solid var(--line-strong);
  border-radius: 99px;
  font-size: 12.5px;
  letter-spacing: 0.06em;
  color: var(--text-dim);
  transition: color 0.35s var(--ease), border-color 0.35s var(--ease);
}

.mini:hover {
  color: var(--text);
  border-color: var(--cyan);
}

.mini--key {
  border-color: rgba(110, 231, 255, 0.45);
  color: var(--cyan);
  background: rgba(110, 231, 255, 0.08);
}

/* ---------------------------- 卡片网格 ---------------------------- */
.cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: clamp(12px, 1.4vw, 18px);
  margin: clamp(22px, 2.4vw, 30px) 0 0;
  padding: 0;
  list-style: none;
}

.card {
  display: flex;
  flex-direction: column;
  border: 1px solid var(--line);
  border-radius: 16px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.035), rgba(255, 255, 255, 0.008));
  overflow: hidden;
  transition: border-color 0.45s var(--ease), transform 0.45s var(--ease);
}

.card:hover {
  border-color: var(--line-strong);
  transform: translateY(-3px);
}

.card__cover {
  display: block;
  aspect-ratio: 16 / 9;
  background: #0b0f1a;
  overflow: hidden;
}

.card__cover img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

/* 没封面的占位块：斜纹，和真封面同尺寸，网格不会被撑歪 */
.card__cover .ph {
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

.card__body {
  flex: 1;
  padding: 14px 15px 10px;
}

.card__tags {
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

.card__title {
  margin-top: 10px;
  font-size: 16px;
  font-weight: 500;
  line-height: 1.45;
}

.card__title a {
  transition: color 0.3s var(--ease);
}

.card__title a:hover {
  color: var(--cyan);
}

.card__excerpt {
  margin-top: 8px;
  font-size: 12.5px;
  line-height: 1.7;
  color: var(--muted);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.card__meta {
  display: flex;
  align-items: center;
  gap: 7px;
  margin-top: 12px;
  font-size: 11.5px;
  color: var(--muted);
}

.card__meta .dot {
  opacity: 0.5;
}

/* ---------------------------- 卡片底部：互动 ---------------------------- */
.card__foot {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 9px 12px;
  border-top: 1px solid var(--line);
}

.act {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 5px 10px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: var(--muted);
  font: inherit;
  font-size: 12px;
  font-variant-numeric: tabular-nums;
  cursor: pointer;
  transition: color 0.3s var(--ease), background 0.3s var(--ease);
}

.act__i {
  font-size: 13px;
  line-height: 1;
}

.act:hover {
  color: var(--text);
  background: rgba(255, 255, 255, 0.05);
}

/* 点过之后点亮：赞=青、收藏=金、拉黑=偏红（负向） */
.act--like.is-on {
  color: var(--cyan);
  background: rgba(110, 231, 255, 0.12);
}

.act--fav.is-on {
  color: var(--gold);
  background: rgba(242, 209, 141, 0.12);
}

.act--block.is-on {
  color: #ff9b9b;
  background: rgba(255, 155, 155, 0.12);
}

.act--go {
  margin-left: auto;
  font-size: 11.5px;
  letter-spacing: 0.04em;
}

.act--go:hover {
  color: var(--cyan);
  background: transparent;
}

@media (max-width: 520px) {
  .cards {
    grid-template-columns: minmax(0, 1fr);
  }
}

@media (prefers-reduced-motion: reduce) {
  .card,
  .card__title a,
  .act {
    transition: none;
  }

  .card:hover {
    transform: none;
  }
}
</style>
