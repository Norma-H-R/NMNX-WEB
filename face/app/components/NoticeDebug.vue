<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useNotices } from '~/composables/useNotices'

/**
 * 通知调试面板 —— **临时件，验收完就删**。
 *
 * 干两件事：
 *   1. 把"进站才会自动触发"的那套通知重播一遍（清掉本地已读再刷新，走完整真实流程）；
 *   2. 现场直推一条通知条，想多看几遍节奏就多点几次，不用刷新。
 * 顺手把本地已读条数摆出来 —— 之前"看不到通知"十有八九就是它把东西吞了，
 * 摆在面板上就不用猜。
 *
 * ⚠️ 删除方法：删掉本文件 + app.vue 里那一行 <NoticeDebug />，别的一概不动。
 *
 * 为什么做在官网这边而不是 admin：要预览的是官网的通知组件（NoticeToast /
 * NoticeModal / NoticeStack），admin 是另一个项目，共享不到这些文件 ——
 * 在那边搭一套仿的，看的就不是真效果了。
 */

const { unreadAnnouncements, unreadTotal, readCount, loadRead } = useNotices()

const open = ref(false)
const ready = ref(false)

// 尺寸要和 NoticeStack 保持一致：条高固定，位置 = 序号 × (条高 + 间距)。
// 少了这一步，几条就会叠在同一个点上（调试面板自己也得守这个规矩）。
const ITEM_H = 90
const GAP = 14
const STEP = ITEM_H + GAP

// 现场推的测试通知：一次推 3 条，专门验"多条会不会叠在同一个位置上"
const demo = ref<{ id: number, title: string, text: string }[]>([])
let seq = 0

onMounted(() => {
  loadRead()
  ready.value = true
})

/** 清掉本地已读再刷新：等于以"新访客"的身份重走一遍进站流程 */
function replay() {
  try {
    localStorage.removeItem('nmnx:notice.v2')
  } catch {
    // 清不掉也照样刷新，最多这次还是已读状态
  }

  location.reload()
}

/**
 * 推一条 —— 照原版 create() 的行为：点一次 push 一条，**已经在上面的不动**，
 * 所以反复点就是一条接一条地累积、上下排开。
 *
 * （之前写成"一次推 3 条 + 整体替换"，既看不出逐条推出的节奏，
 *   点第二下还把前一批顶掉了 —— 那不是原版的行为。）
 */
function pushToast() {
  seq += 1

  demo.value.push({
    id: seq,
    title: `测试通知 ${seq} · 跟随端 v7.1`,
    text: '滑入 → 青紫滑块追赶覆盖 → 文字从左揭示',
  })
}

function closeDemo(id: number) {
  demo.value = demo.value.filter((d) => d.id !== id)
}
</script>

<template>
  <div class="nd">
    <!-- 现场推的通知条：位置、时序、动画跟真实的一模一样（同一套组件） -->
    <div class="nd__stage">
      <NoticeToast
        v-for="(d, i) in demo"
        :key="d.id"
        :title="d.title"
        :text="d.text"
        :offset="i * STEP"
        :ttl="9000"
        @close="closeDemo(d.id)"
      />
    </div>

    <div class="nd__box">
      <button type="button" class="nd__fab" @click="open = !open">
        {{ open ? '收起' : '通知调试' }}
      </button>

      <div v-if="open" class="nd__panel">
        <p class="nd__row">
          <span>未读公告</span>
          <b>{{ ready ? unreadAnnouncements.length : '—' }}</b>
        </p>
        <p class="nd__row">
          <span>未读总数</span>
          <b>{{ ready ? unreadTotal : '—' }}</b>
        </p>
        <p class="nd__row">
          <span>本地已读记录</span>
          <b>{{ ready ? readCount : '—' }}</b>
        </p>

        <button type="button" class="nd__act" @click="pushToast">推一条通知条（可反复点）</button>

        <button type="button" class="nd__act nd__act--warn" @click="replay">
          重播全部（清已读 + 刷新）
        </button>

        <p class="nd__hint">
          「重播」会以新访客身份重走进站流程：先弹公告弹窗，再依次推出通知条。
          验完删掉本组件与 app.vue 里的挂载即可。
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.nd__stage {
  position: fixed;
  top: 96px;
  right: 0;
  /* 与 NoticeStack 保持一致：让开页头，别盖住头像浮层 */
  z-index: 45;
  /* 条自己 absolute 定位（Y 轴由 --y-offset 给），这里只当坐标原点 */
  pointer-events: none;
}

.nd__stage > * {
  pointer-events: auto;
}

/* 面板放右下角，别和右上角的通知条抢地盘 */
.nd__box {
  position: fixed;
  right: 20px;
  bottom: 20px;
  z-index: 71;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 10px;
}

.nd__fab {
  padding: 9px 16px;
  border: 1px dashed rgba(242, 209, 141, 0.55);
  border-radius: 99px;
  background: rgba(242, 209, 141, 0.12);
  color: var(--gold);
  font: inherit;
  font-size: 12.5px;
  letter-spacing: 0.08em;
  cursor: pointer;
  transition: background 0.3s var(--ease), border-color 0.3s var(--ease);
}

.nd__fab:hover {
  background: rgba(242, 209, 141, 0.22);
  border-color: var(--gold);
}

.nd__panel {
  width: 264px;
  padding: 14px;
  border: 1px dashed rgba(242, 209, 141, 0.45);
  border-radius: 14px;
  background: linear-gradient(180deg, rgba(20, 18, 12, 0.97), rgba(10, 9, 6, 0.98));
  -webkit-backdrop-filter: blur(14px);
  backdrop-filter: blur(14px);
  box-shadow: 0 30px 70px -30px rgba(0, 0, 0, 0.95);
}

.nd__row {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  padding: 4px 0;
  font-size: 12px;
  color: var(--muted);
}

.nd__row b {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 13px;
  font-weight: 500;
  color: var(--text);
}

.nd__act {
  display: block;
  width: 100%;
  margin-top: 8px;
  padding: 9px 12px;
  border: 1px solid rgba(242, 209, 141, 0.4);
  border-radius: 9px;
  background: rgba(242, 209, 141, 0.1);
  color: var(--gold);
  font: inherit;
  font-size: 12.5px;
  letter-spacing: 0.04em;
  cursor: pointer;
  transition: background 0.3s var(--ease);
}

.nd__act:hover {
  background: rgba(242, 209, 141, 0.2);
}

.nd__act--warn {
  border-color: rgba(110, 231, 255, 0.4);
  background: rgba(110, 231, 255, 0.1);
  color: var(--cyan);
}

.nd__act--warn:hover {
  background: rgba(110, 231, 255, 0.2);
}

.nd__hint {
  margin-top: 12px;
  padding-top: 10px;
  border-top: 1px dashed rgba(242, 209, 141, 0.25);
  font-size: 11.5px;
  line-height: 1.7;
  color: rgba(124, 132, 155, 0.95);
}

@media (max-width: 520px) {
  .nd__panel {
    width: min(264px, calc(100vw - 40px));
  }
}
</style>
