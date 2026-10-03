<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'
import { useMessage } from 'naive-ui'
import SideNav from '@/components/admin/SideNav.vue'
import TabBar from '@/components/admin/TabBar.vue'
import { ADMIN_MENUS } from '@/router/admin-menu'
import { transitionTo } from '@/composables/usePageVeil'
import { useTabsStore } from '@/stores/tabs'

/**
 * 控制台外壳：侧栏通顶 + 右侧（页签栏 + 内容区）。
 *
 * 布局骨架用 Naive UI 的 Layout 系列。关键在层级：
 *
 *   n-layout(has-sider, absolute)      ← 最外层，flex 行：侧栏 | 右侧
 *     ├─ n-layout-sider               ← 通顶：直接挂在 flex 行里，没有任何 top 偏移
 *     └─ n-layout(.body)              ← 右侧，flex:1，占满剩余宽度
 *          ├─ n-layout-header         ← 页签栏（只占右侧，48px）
 *          └─ n-layout(.content)      ← 内容区，absolute top:48px，自己滚动
 *
 * 之前 header 放在最外层，导致它横贯全宽、把侧栏压在下面 48px —— 那就是
 * "侧栏没通顶"的原因。现在 header 收进右侧，侧栏自然从 0 一直到屏幕底。
 *
 * 三个容易踩的点：
 *   1) 页签数据必须由"路由变化"驱动，而不是由"点菜单"驱动 ——
 *      否则用户直接改地址栏、或页面内 router.push 时，页签栏会和实际页面对不上。
 *   2) KeepAlive 的 include 用路由 name，所以视图组件的 name 必须同名。
 *   3) 模块之间切换要"快"，所以只是轻微淡入上移；
 *      登录/退出那种整屏换场才走闸门过渡（transitionTo）。
 */

const route = useRoute()
const router = useRouter()
const tabs = useTabsStore()
const message = useMessage()

const ready = ref(false)
const collapsed = ref(false)

/**
 * 路由一变就同步页签：没开过的自动开，开过的只激活。
 * watch 的是 fullPath 而不是 name —— 同一模块带不同 query（列表筛选）时也要跟着更新。
 */
watch(
  () => route.fullPath,
  () => {
    if (route.name) tabs.open(route)
  },
  { immediate: true },
)

/** <component :key> 用它：重载计数一变，key 就变，组件强制重建 */
const viewKey = computed(() => {
  const key = String(route.name ?? '')
  return `${key}#${tabs.tokenOf(key)}`
})

/**
 * 侧栏和页签栏共用同一个"切模块"入口。
 *
 * 注意顺序：**先点亮菜单，再推路由**。
 * 不能只推路由等它回调 —— 目标页是懒加载的，首次进入要下载 chunk
 * （dev 下几百毫秒），那期间菜单还是旧的高亮，用户看到的就是
 * "点了没反应，点下一个时上一个才亮"。
 * open() 在路由落地后还会同步一次，两边幂等，谁先到都对。
 */
/**
 * 切模块：只负责推路由，**不碰菜单高亮**。
 *
 * 菜单是非受控的（见 SideNav 里的 default-value），点哪一行它自己立刻变蓝，
 * 不经过任何状态中转，也就不存在"等路由回来才亮"的问题。
 * 这里推完路由就完事。
 */
function selectModule(key: string) {
  if (key === tabs.activeKey) return
  router.push({ name: key })
}

function closeTab(key: string) {
  const next = tabs.close(key)
  if (next) router.push({ name: next })
}

async function reloadTab() {
  const key = tabs.activeKey
  if (!key) return
  await tabs.reload(key)
  message.success('已刷新当前模块')
}

function closeOtherTabs() {
  const key = tabs.activeKey
  if (!key) return
  tabs.closeOthers(key)
}

async function logout() {
  // 整屏换场，走闸门过渡
  await transitionTo(async () => {
    await router.push({ name: 'login' })
  })
  message.success('已安全退出')
}

onMounted(() => {
  // 双 rAF：等首帧按初始样式渲染完再切类，侧栏的滑入动画才会真的播
  requestAnimationFrame(() => requestAnimationFrame(() => (ready.value = true)))
})
</script>

<template>
  <div class="shell">
    <n-layout class="layout" has-sider position="absolute">
      <!-- 侧栏：通顶。不参与任何顶部偏移，所以从 0 一直到底 -->
      <n-layout-sider
        class="sider"
        bordered
        collapse-mode="width"
        :width="240"
        :collapsed-width="68"
        :collapsed="collapsed"
        show-trigger="bar"
        @update:collapsed="collapsed = $event"
      >
        <!-- 这里的 activeKey 只当**初始值**用（菜单是非受控的，见 SideNav 里的说明） -->
        <SideNav
          :collapsed="collapsed"
          :ready="ready"
          :active-key="tabs.activeKey"
          :menus="ADMIN_MENUS"
          @select="selectModule"
          @logout="logout"
        />
      </n-layout-sider>

      <!-- 右侧：页签栏 + 内容区 -->
      <n-layout class="body">
        <n-layout-header class="head" bordered>
          <TabBar
            :items="tabs.items"
            :active-key="tabs.activeKey"
            @select="selectModule"
            @close="closeTab"
            @reload="reloadTab"
            @close-others="closeOtherTabs"
          />
        </n-layout-header>

        <!-- 内容区：滚动交给它（native-scrollbar=false → 用 Naive 的滚动条，颜色在主题里配） -->
        <n-layout
          class="content"
          position="absolute"
          style="top: 48px"
          :native-scrollbar="false"
          content-style="padding: clamp(20px, 2.4vw, 30px) clamp(20px, 2.6vw, 34px) 40px;"
        >
          <RouterView v-slot="{ Component }">
            <Transition name="view" mode="out-in">
              <KeepAlive :include="tabs.cached">
                <component :is="Component" :key="viewKey" />
              </KeepAlive>
            </Transition>
          </RouterView>
        </n-layout>
      </n-layout>
    </n-layout>
  </div>
</template>

<style scoped>
.shell {
  --cyan: #6ee7ff;
  --ease: cubic-bezier(0.22, 1, 0.36, 1);

  position: relative;
  width: 100%;
  height: 100vh;
  height: 100dvh;
  overflow: hidden;
  isolation: isolate;
  /* 后台底色只留一个纯深色。
     原来铺的是"径向渐变 + 网格 + 暗角"三层，衬表格和图表反而容易糊 ——
     按需求先撤掉。将来要加装饰，也建议只加一层、且避开内容区。 */
  background: #06070d;
  color: #e9ecf5;
}

/*
 * 侧栏入场：整条栏从屏幕左侧滑进来。
 *
 * 用 animation 而不是 transition —— 这是一次性的入场，
 * 而 n-layout-sider 自己的折叠动的是宽度（transition），
 * 两者用不同机制才不会在展开/收起时互相打架。
 * 内容的淡入在 SideNav 里单独做，并且刻意比这里晚 0.22s 起。
 */
.sider {
  /* 栏本身 0.85s 滑到位；侧栏内容（品牌 + 菜单）是**同一时刻**开始、
     在栏里面一条条浮现的（见 SideNav 的时间线注释），两者并行不串行 */
  animation: sider-in 0.85s cubic-bezier(0.22, 1, 0.36, 1) both;
}

@keyframes sider-in {
  from {
    transform: translateX(-100%);
  }
  to {
    transform: none;
  }
}

/* 右侧容器：占满剩余宽度，给内容区的 absolute 定位当锚点 */
.body {
  position: relative;
  flex: 1;
  min-width: 0;
}

/* 页签栏高度 48px —— 下面内容区的 top 要跟它对齐 */
.head {
  height: 48px;
}

/* ── 模块切换过渡 ─────────────────────────────────────────────────── */

/*
 * 刻意压得很轻：模块之间是"切页签"，频率很高，动效一重就成了负担。
 * 只有位移和透明度，不做缩放。
 */
.view-enter-active {
  transition:
    opacity 0.26s ease,
    transform 0.26s var(--ease);
}

.view-leave-active {
  transition:
    opacity 0.16s ease,
    transform 0.16s ease;
}

.view-enter-from {
  opacity: 0;
  transform: translateY(8px);
}

.view-leave-to {
  opacity: 0;
  transform: translateY(-6px);
}

@media (prefers-reduced-motion: reduce) {
  .view-enter-active,
  .view-leave-active {
    transition-duration: 0.01ms;
  }

  .view-enter-from,
  .view-leave-to {
    transform: none;
  }
}
</style>
