<script setup lang="ts">
import { computed, ref } from 'vue'
import { fetchMyBlogs, fetchMyStats, type Blog } from '~/composables/useBlog'
import { goWithVeil } from '~/composables/usePageLink'

/**
 * 我的博客（/blog/mine）—— **只有自己看得到**。
 *
 * 用户明确要的两件事：
 *   1. 每篇能看到**点赞 / 收藏 / 拉黑**三个数（拉黑只有作者自己能看到，见文档决策 7）
 *   2. 草稿和已发布分开看
 *
 * 数据走 useBlog 的假数据；正式环境要登录（`auth:member`），未登录应跳登录。
 */

const blogs = ref<Blog[]>(fetchMyBlogs())
const stats = computed(() => fetchMyStats())

const tab = ref<'all' | 'published' | 'draft'>('all')

const list = computed(() => {
  if (tab.value === 'all') return blogs.value

  return blogs.value.filter((b) => b.status === tab.value)
})

const dateText = (iso: string) => iso.slice(0, 10)

const TABS = [
  { key: 'all', label: '全部' },
  { key: 'published', label: '已发布' },
  { key: 'draft', label: '草稿' },
] as const
</script>

<template>
  <section class="section">
    <BackFab fallback="/account" label="用户中心" />

    <div class="container">
      <header class="head" v-reveal="{ selector: '.rv', stagger: 0.1 }">
        <p class="eyebrow rv">我的</p>
        <h1 class="title rv">我的<em>博客</em></h1>
        <p class="lead rv">
          这里只有你自己看得到。被拉黑的次数也是 —— 读者侧不会公开是谁。
        </p>
      </header>

      <!-- 汇总：用户要的"多少点赞、多少收藏、多少拉黑" -->
      <ul class="sum rv" v-reveal>
        <li class="sum__i">
          <span class="sum__k">篇数</span>
          <span class="sum__v">{{ stats.posts }}</span>
        </li>
        <li class="sum__i sum__i--like">
          <span class="sum__k">总点赞</span>
          <span class="sum__v">{{ stats.like }}</span>
        </li>
        <li class="sum__i sum__i--fav">
          <span class="sum__k">总收藏</span>
          <span class="sum__v">{{ stats.favorite }}</span>
        </li>
        <li class="sum__i sum__i--block">
          <span class="sum__k">总拉黑</span>
          <span class="sum__v">{{ stats.block }}</span>
        </li>
        <li class="sum__i">
          <span class="sum__k">总阅读</span>
          <span class="sum__v">{{ stats.view }}</span>
        </li>
      </ul>

      <div class="bar">
        <div class="tabs">
          <button
            v-for="t in TABS"
            :key="t.key"
            type="button"
            class="tab"
            :class="{ 'is-on': tab === t.key }"
            @click="tab = t.key"
          >
            {{ t.label }}
          </button>
        </div>

        <a class="mini mini--key" href="/blog/new" @click="goWithVeil($event, '/blog/new')">写一篇</a>
      </div>

      <ul class="rows">
        <li v-for="b in list" :key="b.id" class="row">
          <div class="row__main">
            <div class="row__top">
              <span v-if="b.status === 'draft'" class="tag tag--draft">草稿</span>
              <a class="row__title" :href="`/blog/${b.slug}`" @click="goWithVeil($event, `/blog/${b.slug}`)">
                {{ b.title }}
              </a>
            </div>
            <p class="row__meta">
              {{ dateText(b.publishedAt) }} · {{ b.stats.view }} 阅读 · {{ b.stats.comment }} 条评论
            </p>
          </div>

          <div class="row__stats">
            <span class="st st--like">♥ {{ b.stats.like }}</span>
            <span class="st st--fav">★ {{ b.stats.favorite }}</span>
            <span class="st st--block">⊘ {{ b.stats.block }}</span>
          </div>

          <div class="row__ops">
            <a class="op" :href="`/blog/edit/${b.id}`" @click="goWithVeil($event, `/blog/edit/${b.id}`)">
              编辑
            </a>
            <a class="op" :href="`/blog/${b.slug}`" @click="goWithVeil($event, `/blog/${b.slug}`)">
              查看
            </a>
          </div>
        </li>
      </ul>

      <p class="back">
        <a class="btn" href="/blog" @click="goWithVeil($event, '/blog')">
          <span>去公共博客</span>
        </a>
      </p>
    </div>
  </section>
</template>

<style scoped>
.head {
  max-width: 640px;
}

/* ---------------------------- 汇总 ---------------------------- */
.sum {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: clamp(10px, 1.2vw, 14px);
  margin: clamp(22px, 2.4vw, 30px) 0 0;
  padding: 0;
  list-style: none;
}

.sum__i {
  display: grid;
  gap: 6px;
  padding: 13px 15px;
  border: 1px solid var(--line);
  border-radius: 14px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.01));
}

.sum__k {
  font-size: 11.5px;
  letter-spacing: 0.14em;
  color: var(--muted);
}

.sum__v {
  font-family: var(--mono);
  font-size: 22px;
  font-weight: 500;
  font-variant-numeric: tabular-nums;
  color: var(--text);
}

.sum__i--like .sum__v {
  color: var(--cyan);
}

.sum__i--fav .sum__v {
  color: var(--gold);
}

.sum__i--block .sum__v {
  color: #ff9b9b;
}

/* ---------------------------- 顶部条 ---------------------------- */
.bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 14px;
  margin-top: clamp(20px, 2.2vw, 28px);
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
  padding: 7px 18px;
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

.tab.is-on {
  color: #06070d;
  background: var(--grad);
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

/* ---------------------------- 列表 ---------------------------- */
.rows {
  margin: 16px 0 0;
  padding: 0;
  list-style: none;
}

.row {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 13px 2px;
  border-top: 1px solid var(--line);
}

.row:last-child {
  border-bottom: 1px solid var(--line);
}

.row__main {
  flex: 1;
  min-width: 0;
}

.row__top {
  display: flex;
  align-items: center;
  gap: 9px;
  min-width: 0;
}

.tag--draft {
  flex: none;
  padding: 2px 8px;
  border: 1px solid rgba(242, 209, 141, 0.4);
  border-radius: 6px;
  font-size: 11px;
  color: var(--gold);
}

.row__title {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 14.5px;
  transition: color 0.3s var(--ease);
}

.row__title:hover {
  color: var(--cyan);
}

.row__meta {
  margin-top: 5px;
  font-size: 12px;
  color: var(--muted);
}

.row__stats {
  display: flex;
  gap: 12px;
  flex: none;
  font-family: var(--mono);
  font-size: 12.5px;
  font-variant-numeric: tabular-nums;
}

.st--like {
  color: var(--cyan);
}

.st--fav {
  color: var(--gold);
}

.st--block {
  color: #ff9b9b;
}

.row__ops {
  display: flex;
  gap: 6px;
  flex: none;
}

.op {
  padding: 5px 12px;
  border: 1px solid var(--line);
  border-radius: 99px;
  font-size: 12px;
  color: var(--text-dim);
  transition: color 0.3s var(--ease), border-color 0.3s var(--ease);
}

.op:hover {
  color: var(--cyan);
  border-color: var(--cyan);
}

.back {
  margin-top: clamp(24px, 2.6vw, 34px);
}

@media (max-width: 700px) {
  .row {
    flex-wrap: wrap;
    gap: 10px;
  }

  .row__stats {
    order: 3;
  }
}

@media (prefers-reduced-motion: reduce) {
  .tab,
  .mini,
  .row__title,
  .op {
    transition: none;
  }
}
</style>
