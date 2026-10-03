<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useNotices } from '~/composables/useNotices'

/**
 * 系统公告的默认弹窗。
 *
 * 挂在 app.vue（全站外壳）上，所以任何一页进来只要还有未读公告就会弹。
 * 关掉任意一条就记为已读，下一条未读会接着弹；全部读完就不再出现。
 *
 * 两个与 SSR 有关的注意点：
 *   1. 已读状态存在 localStorage，服务端读不到。所以进站先 `ready = false`，
 *      等 onMounted 读完再决定弹不弹 —— 否则服务端按"未读"渲染、客户端按"已读"
 *      渲染，会直接撞 hydration。
 *   2. 弹窗还要等首屏的入场动画先走完（这里给 600ms），一进来就糊一张弹窗
 *      会显得很急。
 */

const { latestUnread, markRead, loadRead } = useNotices()

const ready = ref(false)
let timer = 0

onMounted(() => {
  loadRead()
  timer = window.setTimeout(() => (ready.value = true), 600)
})

onBeforeUnmount(() => clearTimeout(timer))

const visible = computed(() => ready.value && !!latestUnread.value)

// 关掉 = 已读。多条未读时会自然顺延到下一条继续弹。
function dismiss() {
  if (latestUnread.value) markRead(latestUnread.value.id)
}

watch(visible, (v) => {
  if (typeof document === 'undefined') return
  document.body.classList.toggle('is-locked', v)
})

function onKey(e) {
  if (e.key === 'Escape' && visible.value) dismiss()
}

onMounted(() => window.addEventListener('keydown', onKey))

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  if (typeof document !== 'undefined') document.body.classList.remove('is-locked')
})
</script>

<template>
  <Teleport to="body">
    <Transition name="notice">
      <div v-if="visible" class="wrap">
        <div class="mask" @click="dismiss" />

        <div class="card" role="dialog" aria-modal="true" aria-label="系统公告">
          <header class="head">
            <span class="tag" :class="`is-${latestUnread.level || 'info'}`">系统公告</span>
            <span class="date">{{ latestUnread.time }}</span>
          </header>

          <h3 class="title">{{ latestUnread.title }}</h3>
          <p class="body">{{ latestUnread.text }}</p>

          <footer class="foot">
            <span class="hint">关闭后不再重复提示</span>
            <button type="button" class="ok" @click="dismiss">我知道了</button>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.wrap {
  position: fixed;
  inset: 0;
  /* 盖在页头（50）、订单抽屉（63）、详情弹窗（64）之上，但要让开换页闸门（70） */
  z-index: 66;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.mask {
  position: absolute;
  inset: 0;
  background: rgba(3, 4, 9, 0.68);
  -webkit-backdrop-filter: blur(4px);
  backdrop-filter: blur(4px);
}

.card {
  position: relative;
  width: min(520px, 94vw);
  padding: 24px;
  border: 1px solid var(--line-strong);
  border-radius: 20px;
  background: linear-gradient(180deg, rgba(16, 20, 34, 0.99), rgba(8, 10, 18, 0.99));
  box-shadow:
    0 50px 120px -50px rgba(0, 0, 0, 0.95),
    0 0 0 1px rgba(110, 231, 255, 0.06),
    inset 0 1px 0 rgba(255, 255, 255, 0.06);
  overflow: hidden;
}

/* 顶部一道青光，让弹窗比普通卡片"亮"一点 */
.card::before {
  content: '';
  position: absolute;
  inset: 0 0 auto;
  height: 1px;
  background: linear-gradient(90deg, transparent, rgba(110, 231, 255, 0.75), transparent);
}

.head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.tag {
  padding: 4px 11px;
  border: 1px solid transparent;
  border-radius: 99px;
  font-size: 11.5px;
  letter-spacing: 0.12em;
}

.tag.is-info {
  color: var(--cyan);
  border-color: rgba(110, 231, 255, 0.38);
  background: rgba(110, 231, 255, 0.1);
}

.tag.is-warn {
  color: var(--gold);
  border-color: rgba(242, 209, 141, 0.38);
  background: rgba(242, 209, 141, 0.1);
}

.date {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12px;
  letter-spacing: 0.04em;
  color: var(--muted);
}

.title {
  margin-top: 16px;
  font-size: clamp(18px, 2vw, 22px);
  font-weight: 500;
  line-height: 1.4;
  letter-spacing: 0.01em;
}

.body {
  margin-top: 12px;
  font-size: 14px;
  line-height: 1.9;
  color: var(--text-dim);
}

.foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  margin-top: 22px;
  padding-top: 16px;
  border-top: 1px solid var(--line);
}

.hint {
  font-size: 12px;
  color: var(--muted);
}

.ok {
  flex: none;
  padding: 10px 24px;
  border: 1px solid transparent;
  border-radius: 99px;
  background: var(--grad);
  color: #06070d;
  font: inherit;
  font-size: 13.5px;
  font-weight: 500;
  letter-spacing: 0.06em;
  cursor: pointer;
  transition: filter 0.35s var(--ease), transform 0.35s var(--ease),
    box-shadow 0.35s var(--ease);
}

.ok:hover {
  filter: brightness(1.08);
  transform: translateY(-2px);
  box-shadow: 0 14px 32px -14px rgba(110, 231, 255, 0.9);
}

.notice-enter-active,
.notice-leave-active {
  transition: opacity 0.4s var(--ease);
}

.notice-enter-active .card {
  transition: transform 0.5s var(--ease), opacity 0.4s var(--ease);
}

.notice-leave-active .card {
  transition: transform 0.24s var(--ease-soft), opacity 0.2s var(--ease);
}

.notice-enter-from,
.notice-leave-to {
  opacity: 0;
}

.notice-enter-from .card,
.notice-leave-to .card {
  transform: translateY(18px) scale(0.98);
  opacity: 0;
}

@media (max-width: 520px) {
  .foot {
    flex-direction: column;
    align-items: stretch;
  }

  .ok {
    width: 100%;
  }
}

@media (prefers-reduced-motion: reduce) {
  .ok,
  .notice-enter-active,
  .notice-leave-active,
  .notice-enter-active .card,
  .notice-leave-active .card {
    transition: none;
  }
}
</style>
