<script setup lang="ts">
import NavIcon from './NavIcon.vue'
import type { TabItem } from '@/stores/tabs'

/**
 * 顶部页签栏 —— 用 Naive UI 的 n-tabs，不再手写。
 *
 * 借 n-tabs 白拿的东西：标签排布、关闭按钮、键盘左右键切换、
 * 标签溢出时自动收进下拉菜单、以及一串无障碍属性。
 *
 * 唯一"非标准"的地方：我们只借它的**标签栏**，页面内容由外面的 RouterView 渲染，
 * 所以下面每个 n-tab-pane 都是空的（只有 tab 文字），面板容器用 CSS 压掉。
 * 这比自己重写一套标签栏划算得多 —— 也因为"标签栏"本来就是 tabs 这个组件的本职。
 *
 * 页签数据全在 stores/tabs 里，这里只抛事件；路由跳转由 AdminLayout 决定。
 */
defineProps<{ items: TabItem[]; activeKey: string }>()

const emit = defineEmits<{
  select: [key: string]
  close: [key: string]
  reload: []
  closeOthers: []
}>()

function onSelect(key: string) {
  emit('select', key)
}

function onClose(key: string) {
  emit('close', key)
}
</script>

<template>
  <div class="tabbar">
    <n-tabs
      class="tabs"
      :value="activeKey"
      type="card"
      size="small"
      closable
      :tabs-padding="6"
      @update:value="onSelect"
      @close="onClose"
    >
      <!--
        标签文字必须走 #tab 插槽，不能用 :tab 属性：
        实测 :tab="t.title" 传了字符串，Naive 渲染出来的 label 却是空注释节点
        （页签缩成一个 20px 的小方块，文字完全消失）；插槽直出 VNode，稳。
      -->
      <n-tab-pane
        v-for="t in items"
        :key="t.key"
        :name="t.key"
        :closable="!t.affix"
      >
        <template #tab>{{ t.title }}</template>
      </n-tab-pane>
    </n-tabs>

    <div class="tools">
      <n-button quaternary size="small" title="刷新当前模块" @click="emit('reload')">
        <NavIcon name="refresh" />
      </n-button>
      <n-button quaternary size="small" title="关闭其它页签" @click="emit('closeOthers')">
        <NavIcon name="panels" />
      </n-button>
    </div>
  </div>
</template>

<style scoped>
.tabbar {
  display: flex;
  align-items: center;
  height: 48px;
  background: linear-gradient(180deg, rgba(10, 13, 26, 0.7), rgba(7, 9, 18, 0.55));
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}

.tabs {
  flex: 1;
  min-width: 0;
  padding: 0 12px;
}

/*
 * 这里只借标签栏，内容由 RouterView 出 —— 把 n-tabs 自带的面板容器压掉，
 * 否则它会在标签下面留出一块空白区域。
 */
.tabs :deep(.n-tabs-pane-wrapper) {
  display: none;
}

/* n-tabs 默认带一条贯穿的分隔线，我们改用 tabbar 自己的底边 */
.tabs :deep(.n-tabs-nav) {
  padding-bottom: 0;
}

.tabs :deep(.n-tabs-nav-scroll-content) {
  gap: 6px;
}

/* 标签：贴着 32px 高、加一圈描边，和外壳的玻璃感对齐（颜色由 themeOverrides.Tabs 管） */
.tabs :deep(.n-tabs-tab) {
  height: 32px;
  padding: 0 11px;
  border-radius: 9px;
  transition:
    background-color 0.26s ease,
    color 0.26s ease,
    border-color 0.26s ease;
}

.tabs :deep(.n-tabs-tab--active) {
  border-color: rgba(110, 231, 255, 0.32);
  box-shadow: 0 0 20px -6px rgba(110, 231, 255, 0.7);
}

.tabs :deep(.n-tabs-tab__label) {
  font-size: 12.5px;
  letter-spacing: 0.04em;
}

.tools {
  display: flex;
  align-items: center;
  gap: 4px;
  flex: none;
  padding: 0 12px;
  border-left: 1px solid rgba(255, 255, 255, 0.06);
}
</style>
