<script setup lang="ts">
import { computed, ref } from 'vue'
import { BOARDS, boardName, fetchBoards, fetchPosts, forumStats } from '~/composables/useForum'
import { goWithVeil } from '~/composables/usePageLink'

/**
 * 论坛首页（/forum）。
 *
 * 信息密度比博客高一档 —— 论坛要一眼看清"哪儿有新鲜事"：
 *   顶部一行统计 → 版块横排（点一下筛这个版）→ 帖子列表（置顶在最前、按最后回复倒序）
 *
 * 右上角「发帖」进 `/forum/new`；帖子点进 `/forum/{slug}`，评论区复用同一套插件。
 */

const route = useRoute()

const boards = ref(fetchBoards())
const stats = ref(forumStats())

/** 当前筛的版块（走 query，从别处链接过来也能直接落在某个版） */
const active = computed(() => {
  const b = String(route.query.board ?? '')

  return BOARDS.some((x) => x.slug === b) ? b : ''
})

const posts = computed(() => fetchPosts(active.value || undefined))

function pickBoard(e: MouseEvent, slug: string) {
  const target = active.value === slug ? '/forum' : `/forum?board=${slug}`
  goWithVeil(e, target)
}

const dateText = (iso: string) => iso.slice(0, 10)

/** 相对时间：只到"天"，不引 dayjs */
function ago(iso: string) {
  const diff = Date.UTC(2026, 9, 4, 12) - new Date(iso).getTime()
  const h = Math.floor(diff / 3_600_000)
  if (h < 1) return '刚刚'
  if (h < 24) return `${h} 小时前`

  return `${Math.max(1, Math.floor(h / 24))} 天前`
}
</script>

<template>
  <section class="section">
    <div class="container">
      <header class="head" v-reveal="{ selector: '.rv', stagger: 0.1 }">
        <p class="eyebrow rv">论坛</p>
        <h1 class="title rv">有问题就问，<em>有经验就写</em></h1>
        <p class="lead rv">
          求助、分享、讨论都在这儿。帖子不分长短，把话说清楚就行。
        </p>
      </header>

      <!-- 统计条 -->
      <ul class="stats rv" v-reveal>
        <li><span class="stats__k">帖子</span><span class="stats__v">{{ stats.posts }}</span></li>
        <li><span class="stats__k">回复</span><span class="stats__v">{{ stats.replies }}</span></li>
        <li><span class="stats__k">版块</span><span class="stats__v">{{ stats.boards }}</span></li>
        <li><span class="stats__k">今日新增</span><span class="stats__v">{{ stats.today }}</span></li>
      </ul>

      <!-- 版块：点一下筛这个版 -->
      <div class="boards">
        <button
          type="button"
          class="board"
          :class="{ 'is-on': !active }"
          @click="pickBoard($event, '')"
        >
          <span class="board__name">全部</span>
          <span class="board__n">{{ stats.posts }}</span>
        </button>

        <button
          v-for="b in boards"
          :key="b.id"
          type="button"
          class="board"
          :class="{ 'is-on': active === b.slug }"
          :style="{ '--h': b.hue }"
          @click="pickBoard($event, b.slug)"
        >
          <span class="board__name">{{ b.name }}</span>
          <span class="board__n">{{ b.postCount }}</span>
          <span class="board__desc">{{ b.desc }}</span>
        </button>
      </div>

      <!-- 工具条 -->
      <div class="bar">
        <span class="bar__c">{{ posts.length }} 个帖子</span>
        <a class="mini mini--key" href="/forum/new" @click="goWithVeil($event, '/forum/new')">
          发帖
        </a>
      </div>

      <!-- 帖子列表 -->
      <ul class="posts">
        <li v-for="p in posts" :key="p.id" class="post">
          <a
            class="post__hit"
            :href="`/forum/${p.slug}`"
            @click="goWithVeil($event, `/forum/${p.slug}`)"
          >
            <span class="post__avatar">{{ p.author.name.charAt(0) }}</span>

            <span class="post__main">
              <span class="post__top">
                <span v-if="p.pin" class="pin">置顶</span>
                <span class="post__title">{{ p.title }}</span>
              </span>
              <span class="post__ex">{{ p.excerpt }}</span>
              <span class="post__meta">
                <span class="post__board">{{ boardName(p.boardId) }}</span>
                <span class="dot">·</span>
                <span>{{ p.author.name }}</span>
                <span class="dot">·</span>
                <span>{{ ago(p.createdAt) }}</span>
              </span>
            </span>

            <span class="post__nums">
              <span class="num"><b>{{ p.commentCount }}</b><i>回复</i></span>
              <span class="num"><b>{{ p.views }}</b><i>浏览</i></span>
            </span>
          </a>
        </li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
.head {
  max-width: 660px;
}

/* ---------------------------- 统计条 ---------------------------- */
.stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: clamp(10px, 1.2vw, 14px);
  margin: clamp(22px, 2.4vw, 30px) 0 0;
  padding: 0;
  list-style: none;
}

.stats li {
  display: grid;
  gap: 5px;
  padding: 12px 14px;
  border: 1px solid var(--line);
  border-radius: 13px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.01));
}

.stats__k {
  font-size: 11px;
  letter-spacing: 0.14em;
  color: var(--muted);
}

.stats__v {
  font-family: var(--mono);
  font-size: 19px;
  font-weight: 500;
  font-variant-numeric: tabular-nums;
}

/* ---------------------------- 版块 ---------------------------- */
.boards {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: clamp(18px, 2vw, 26px);
}

.board {
  position: relative;
  display: grid;
  gap: 3px;
  padding: 10px 14px;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: transparent;
  color: var(--text-dim);
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition: border-color 0.35s var(--ease), background 0.35s var(--ease);
}

.board:hover {
  border-color: var(--line-strong);
}

/* 选中的版块：描边上该版的主题色 */
.board.is-on {
  border-color: hsl(var(--h, 192), 82%, 62%, 0.55);
  background: hsl(var(--h, 192), 82%, 62%, 0.08);
}

.board__name {
  font-size: 13.5px;
}

.board__n {
  font-family: var(--mono);
  font-size: 11px;
  color: var(--muted);
}

.board__desc {
  display: none;
}

/* ---------------------------- 工具条 ---------------------------- */
.bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  margin-top: clamp(20px, 2.2vw, 28px);
}

.bar__c {
  font-family: var(--mono);
  font-size: 12.5px;
  color: var(--muted);
}

.mini {
  padding: 7px 16px;
  border: 1px solid var(--line-strong);
  border-radius: 99px;
  font-size: 12.5px;
  letter-spacing: 0.06em;
  transition: color 0.35s var(--ease), border-color 0.35s var(--ease);
}

.mini--key {
  border-color: rgba(110, 231, 255, 0.45);
  color: var(--cyan);
  background: rgba(110, 231, 255, 0.08);
}

.mini--key:hover {
  background: rgba(110, 231, 255, 0.16);
}

/* ---------------------------- 帖子列表 ---------------------------- */
.posts {
  margin: 10px 0 0;
  padding: 0;
  list-style: none;
}

.post + .post {
  border-top: 1px solid var(--line);
}

.post__hit {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 14px 4px;
  transition: background 0.3s var(--ease);
}

.post__hit:hover {
  background: rgba(255, 255, 255, 0.025);
}

.post__avatar {
  flex: none;
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  margin-top: 2px;
  border-radius: 9px;
  background: rgba(255, 255, 255, 0.06);
  color: var(--text-dim);
  font-size: 12.5px;
}

.post__main {
  flex: 1;
  min-width: 0;
}

.post__top {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.pin {
  flex: none;
  padding: 1px 7px;
  border: 1px solid rgba(242, 209, 141, 0.45);
  border-radius: 5px;
  font-size: 10.5px;
  color: var(--gold);
}

.post__title {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 14.5px;
  transition: color 0.3s var(--ease);
}

.post__hit:hover .post__title {
  color: var(--cyan);
}

.post__ex {
  display: block;
  margin-top: 5px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 12.5px;
  color: var(--muted);
}

.post__meta {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 7px;
  font-size: 11.5px;
  color: var(--muted);
}

.post__board {
  padding: 1px 7px;
  border: 1px solid var(--line);
  border-radius: 5px;
}

.dot {
  opacity: 0.5;
}

.post__nums {
  flex: none;
  display: flex;
  gap: 16px;
  padding-top: 3px;
}

.num {
  display: grid;
  justify-items: center;
  gap: 2px;
}

.num b {
  font-family: var(--mono);
  font-size: 13.5px;
  font-weight: 500;
  color: var(--text-dim);
}

.num i {
  font-style: normal;
  font-size: 10.5px;
  color: var(--muted);
}

@media (max-width: 640px) {
  .post__nums {
    display: none;
  }
}
</style>
