<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useNotices } from '~/composables/useNotices'
import { goWithVeil } from '~/composables/usePageLink'
import { transitionTo } from '~/composables/usePageVeil'
import type { Notice } from '~/composables/useNotices'

/**
 * 通知列表页（/notices）—— 用户中心「历史通知」的落点。
 *
 * 和站内其它位置的分工：
 *   · 右侧流光通知条（NoticeStack）只推普通公告，几秒就没了；
 *   · 弹窗（NoticeModal）只弹"需确认"的公告；
 *   · **这一页才是全部**：公告 + 博客 / 论坛的回复，一条不落，随时回来翻。
 *
 * 可点击的（带详情正文的）才给「›」标识，点进去是 /notices/{id}；
 * 纯通报的点不动 —— 判断统一走 useNotices 的 noticeLink()。
 */

const route = useRoute()
const { notices, isRead, markRead, loadRead, noticeLink } = useNotices()

/**
 * 筛选：全部 / 公告 / 通知（回复）。
 * 读 query 而不是本地 state —— 页头头像那张卡里的两个入口会带 ?type= 过来，
 * 从浮层点进来要能直接落在对应分类上。
 */
const filter = computed(() => {
  const t = String(route.query.type ?? '')

  return t === 'announcement' || t === 'reply' ? t : ''
})

const list = computed(() =>
  filter.value ? notices.value.filter((n) => n.kind === filter.value) : notices.value,
)

const TABS = [
  { key: '', label: '全部' },
  { key: 'announcement', label: '公告' },
  { key: 'reply', label: '通知' },
]

/** 切分类走轻量跳转，不用闸门过渡 —— 同一页里换个筛选，没必要合拢一次屏 */
function pick(key: string) {
  navigateTo(key ? `/notices?type=${key}` : '/notices', { replace: true })
}

// 已读状态在 localStorage，服务端读不到；等客户端读完再按真实状态上色，
// 免得服务端画成"全未读"、客户端又是另一套，直接撞 hydration
const ready = ref(false)

onMounted(() => {
  loadRead()
  ready.value = true
})

useHead({ title: '历史通知 · 南门拈星' })

const kindLabel = (n: Notice) => (n.kind === 'announcement' ? '公告' : '回复')

const dotClass = (n: Notice) => {
  if (n.kind !== 'announcement') return 'is-reply'

  return n.level === 'warn' ? 'is-warn' : 'is-ann'
}

function open(n: Notice) {
  markRead(n.id)

  const to = noticeLink(n)
  if (!to) return // 纯通报，没有详情可看

  transitionTo(() => navigateTo(to))
}
</script>

<template>
  <section class="section">
    <BackFab fallback="/account" label="上一页" />

    <div class="container">
      <header class="head" v-reveal="{ selector: '.rv', stagger: 0.1 }">
        <p class="eyebrow rv">通知</p>
        <h1 class="title rv">历史<em>通知</em></h1>
        <p class="lead rv">
          系统公告，以及你参与过的博客 / 论坛回复，都留在这里。带「›」的可以点进去看详情。
        </p>
      </header>

      <div class="tabs">
        <button
          v-for="t in TABS"
          :key="t.key"
          type="button"
          class="tab"
          :class="{ 'is-on': filter === t.key }"
          @click="pick(t.key)"
        >
          {{ t.label }}
        </button>
      </div>

      <ul class="list">
        <li
          v-for="n in list"
          :key="n.id"
          class="row"
          :class="{ 'is-read': ready && isRead(n), 'is-link': !!noticeLink(n) }"
          @click="open(n)"
        >
          <span class="row__dot" :class="dotClass(n)" aria-hidden="true" />

          <span class="row__main">
            <span class="row__top">
              <span class="row__kind">{{ kindLabel(n) }}</span>
              <span class="row__title">{{ n.title }}</span>
            </span>
            <span class="row__text">{{ n.text }}</span>
          </span>

          <span class="row__side">
            <span class="row__time">{{ n.time }}</span>
            <span v-if="noticeLink(n)" class="row__go" aria-hidden="true">›</span>
          </span>
        </li>
      </ul>

      <p class="back">
        <a class="btn" href="/account" @click="goWithVeil($event, '/account')">
          <span>回到用户中心</span>
        </a>
      </p>
    </div>
  </section>
</template>

<style scoped>
/* 内容整体收窄：这是"读消息"的页，铺满整屏反而难扫 */
.container > * {
  max-width: 900px;
}

.head {
  max-width: 640px;
}

/* 分类切换：与用户中心那排胶囊同一套样式 */
.tabs {
  display: inline-flex;
  gap: 4px;
  margin-top: clamp(18px, 2vw, 24px);
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

.list {
  margin: clamp(22px, 2.4vw, 30px) 0 0;
  padding: 0;
  list-style: none;
}

.row {
  display: flex;
  align-items: center;
  gap: 12px;
  /* 紧凑：一行压到 12px 上下留白，扫起来更快 */
  padding: 12px 2px;
  border-top: 1px solid var(--line);
  transition: background 0.35s var(--ease);
}

.row:last-child {
  border-bottom: 1px solid var(--line);
}

.row.is-link {
  cursor: pointer;
}

.row.is-link:hover {
  background: rgba(255, 255, 255, 0.03);
}

/* 圆点：公告青、公告-需注意金、回复紫 */
.row__dot {
  flex: none;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--cyan);
  box-shadow: 0 0 0 3px rgba(110, 231, 255, 0.12);
}

.row__dot.is-warn {
  background: var(--gold);
  box-shadow: 0 0 0 3px rgba(242, 209, 141, 0.16);
}

.row__dot.is-reply {
  background: var(--violet);
  box-shadow: 0 0 0 3px rgba(167, 139, 250, 0.14);
}

.row__main {
  flex: 1;
  min-width: 0;
}

.row__top {
  display: flex;
  align-items: baseline;
  gap: 10px;
  min-width: 0;
}

.row__kind {
  flex: none;
  padding: 2px 8px;
  border: 1px solid var(--line);
  border-radius: 6px;
  font-size: 11px;
  letter-spacing: 0.1em;
  color: var(--muted);
}

.row__title {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 15px;
  transition: color 0.35s var(--ease);
}

.row.is-link:hover .row__title {
  color: var(--cyan);
}

.row__text {
  display: block;
  margin-top: 5px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 12.5px;
  color: var(--muted);
}

.row__side {
  flex: none;
  display: flex;
  align-items: center;
  gap: 10px;
}

.row__time {
  font-family: var(--mono);
  font-size: 12px;
  letter-spacing: 0.04em;
  color: var(--muted);
}

.row__go {
  font-size: 17px;
  line-height: 1;
  color: var(--cyan);
  opacity: 0.6;
  transition: transform 0.3s var(--ease), opacity 0.3s var(--ease);
}

.row.is-link:hover .row__go {
  opacity: 1;
  transform: translateX(2px);
}

/* 已读的整条压暗，但不禁用 —— 还能点进去回看 */
.row.is-read .row__title {
  color: var(--muted);
}

.row.is-read .row__dot {
  background: #3a4157;
  box-shadow: none;
}

.row.is-read .row__text {
  opacity: 0.6;
}

.back {
  margin-top: clamp(24px, 2.6vw, 34px);
}

@media (max-width: 640px) {
  .row__kind {
    display: none;
  }

  .row__side {
    flex-direction: column;
    align-items: flex-end;
    gap: 4px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .row,
  .row__title,
  .row__go {
    transition: none;
  }
}
</style>
