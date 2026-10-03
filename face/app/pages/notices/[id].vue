<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useNotices } from '~/composables/useNotices'
import { goWithVeil } from '~/composables/usePageLink'

/**
 * 通知详情页（/notices/{id}）。
 *
 * 两个入口都落到这里：
 *   · 右侧通知条上点整条（带「›」标识的那些）；
 *   · 历史通知列表里点某一行。
 *
 * 进来就顺手标记已读 —— 这也是"看过就不在页头红点里算了"的那一步。
 * 找不到（id 拼错、或将来数据从接口来之后被删了）不报 404，
 * 给一句说明 + 回列表的路，别把人扔在死胡同里。
 */

const route = useRoute()
const { findNotice, markRead, loadRead } = useNotices()

const notice = computed(() => findNotice(String(route.params.id)))

onMounted(() => {
  loadRead()
  if (notice.value) markRead(notice.value.id)

  // 详情页一律从头看起 —— 从滚到一半的长列表点进来时，不滚顶会停在中间
  window.scrollTo(0, 0)
})

useHead(() => ({
  title: notice.value ? `${notice.value.title} · 通知` : '通知 · 南门拈星',
}))

</script>

<template>
  <section class="section">
    <div class="container narrow">
      <template v-if="notice">
        <!-- 返回统一交给右侧中间那颗悬浮按钮（BackFab），页面里不再各自放一个 -->
        <BackFab fallback="/notices" label="全部通知" />

        <header class="head" v-reveal="{ selector: '.rv', stagger: 0.1 }">
          <div class="head__meta rv">
            <span class="kind" :class="notice.kind === 'reply' ? 'is-reply' : ''">
              {{ notice.kind === 'announcement' ? '系统公告' : '回复通知' }}
            </span>
            <span class="time">{{ notice.time }}</span>
          </div>

          <h1 class="title rv">{{ notice.title }}</h1>
        </header>

        <div class="body rv" v-reveal="{ y: 24, duration: 1 }">
          <p v-for="(p, i) in notice.body" :key="i">{{ p }}</p>
        </div>

        <p class="acts">
          <a class="btn" href="/notices" @click="goWithVeil($event, '/notices')">
            <span>全部通知</span>
          </a>
          <a class="btn btn--ghost" href="/account" @click="goWithVeil($event, '/account')">
            <span>去用户中心</span>
          </a>
        </p>
      </template>

      <template v-else>
        <p class="eyebrow">通知</p>
        <h1 class="title">没有找到<em>这条通知</em></h1>
        <p class="lead">它可能已经被撤回了，或者链接拼错了。</p>

        <p class="acts">
          <a class="btn" href="/notices" @click="goWithVeil($event, '/notices')">
            <span>看看全部通知</span>
          </a>
        </p>
      </template>
    </div>
  </section>
</template>

<style scoped>
.narrow {
  /* 收窄：这是一页文字，宽度一上去每行就太长、读起来散 */
  max-width: 660px;
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

.head__meta {
  display: flex;
  align-items: center;
  gap: 12px;
}

.kind {
  padding: 3px 10px;
  border: 1px solid rgba(110, 231, 255, 0.34);
  border-radius: 99px;
  font-size: 11.5px;
  letter-spacing: 0.1em;
  color: var(--cyan);
  background: rgba(110, 231, 255, 0.09);
}

.kind.is-reply {
  border-color: rgba(167, 139, 250, 0.34);
  color: var(--violet);
  background: rgba(167, 139, 250, 0.09);
}

.time {
  font-family: var(--mono);
  font-size: 12.5px;
  letter-spacing: 0.04em;
  color: var(--muted);
}

.title {
  margin-top: 14px;
}

.body {
  margin-top: clamp(22px, 2.4vw, 32px);
  padding-top: clamp(20px, 2.2vw, 28px);
  border-top: 1px solid var(--line);
}

.body p {
  font-size: 15px;
  line-height: 1.9;
  color: var(--text-dim);
}

.body p + p {
  margin-top: 15px;
}

.acts {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: clamp(26px, 2.8vw, 38px);
}

@media (prefers-reduced-motion: reduce) {
  .back {
    transition: none;
  }
}
</style>
