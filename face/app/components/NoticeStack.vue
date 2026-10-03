<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useNotices } from '~/composables/useNotices'

/**
 * 通知条的队列 —— 排布逻辑照搬原版 PipelineNotificationSystem。
 *
 * 原版的做法：
 *   this.height = 90; this.gap = 15;
 *   create()               → initialY = queue.length * (height + gap)，写进 --y-offset
 *   recalculatePositions() → 按新下标逐个重写 --y-offset，靠 transform 过渡把
 *                            下面那些平滑地往上带
 *
 * 这里把"逐个重写"换成 v-for 的下标：第 n 条的 offset 就是 n × (条高 + 间距)。
 * 从数组里摘掉一条，后面那些的下标自动往前挪，绑定的 offset 跟着变，
 * transform 的 transition 就把它们送上去了 —— 效果一样，还不用手工维护队列。
 *
 * 所以条高必须是固定值（下面 ITEM_H），这也正是原版写死 90 的原因。
 *
 * 只管普通公告（info 级）：需要确认的（warn 级）走弹窗。
 * 博客 / 论坛的回复不进任何全局提示，只在用户中心的「通知」里看。
 */

const { unreadToastNotices, markRead, loadRead, noticeLink } = useNotices()

const ITEM_H = 90
const GAP = 14
const STEP = ITEM_H + GAP

interface Item {
  id: string
  title: string
  text: string
  /** 详情页路径；空串表示这条不可点击（没有详情可看） */
  to: string
}

const items = ref<Item[]>([])

let timers: number[] = []

onMounted(() => {
  loadRead()

  // 进站快照：这份名单就是要推的全部内容，之后别处怎么标记已读都跟这里无关
  // （早先是实时监听未读列表，结果弹窗一关就把通知条取消了）
  const batch = unreadToastNotices.value.slice(0, 3)

  // 一条接一条地出：推出一条 → 停留 ttl → 退场 → 下一条已经在后面排着，
  // 前一条一走，它自己就往上补位
  timers = batch.map((n, i) =>
    window.setTimeout(
      () => {
        items.value.push({ id: n.id, title: n.title, text: n.text, to: noticeLink(n) })
      },
      1200 + i * 1100,
    ),
  )
})

onBeforeUnmount(() => timers.forEach(clearTimeout))

function onClose(id: string) {
  // 摘掉这一条：后面那些的下标往前挪，offset 变了就会自己往上移
  items.value = items.value.filter((it) => it.id !== id)
  markRead(id)
}
</script>

<template>
  <!--
    整块传到 body：页面内容挂在 main 里（main 自带 z-index，等于起了个堆叠上下文），
    留在里面 z-index 给多高都压不过 fixed 的页头。
  -->
  <Teleport to="body">
    <div class="stack" aria-live="polite">
      <NoticeToast
        v-for="(it, i) in items"
        :key="it.id"
        :title="it.title"
        :text="it.text"
        :to="it.to"
        :offset="i * STEP"
        @close="onClose(it.id)"
      />
    </div>
  </Teleport>
</template>

<style scoped>
.stack {
  position: fixed;
  top: 96px;
  right: 0;
  /*
   * 压在页面内容之上、但**让开页头**（z-index 50）：
   * 通知条是非模态的轻提示，不该盖住头像那张悬浮卡 —— 两者都在右上角，
   * 之前给 68 的结果就是鼠标一过头像，浮层被通知条挡了个正着。
   * 它在 96px 以下，所以不会被页头本身挡住。
   */
  z-index: 45;
  /* 条自己 absolute 定位（Y 轴由 --y-offset 给），容器只当坐标原点，不参与排布 */
  pointer-events: none;
}

.stack > * {
  pointer-events: auto;
}
</style>
