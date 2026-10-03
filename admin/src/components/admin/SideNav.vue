<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import NavIcon from './NavIcon.vue'
import type { AdminMenu } from '@/router/admin-menu'

/**
 * 左侧导航 —— **手写**，不再用 n-menu。
 *
 * 为什么换回手写（这是一次刻意的取舍，不是回退）：
 *   n-menu 的"哪一行高亮"是它**内部**管理的。我们只能通过 `value` / `default-value`
 *   影响它，而那条链路里夹着框架的状态合并、渲染批次、受控判断 ——
 *   在实际使用中出现过"点了这行，上一行才亮"的现象，而且**在我这边怎么测都是对的**，
 *   查不出来。用户要的东西本来就极简：
 *     「点击这一行，它就变蓝。就一个点击事件。」
 *   手写之后，这一行高亮的链条只有三步、全在这个文件里：
 *     点击 → 改 `selected` → 模板 `:class="{ 'is-active': ... }"`
 *   没有中间环节，也就没有查不出的环节。
 *
 * 代价（明写在最前面，免得以后有人以为漏了）：
 *   - 键盘上下键切换、`aria-selected` 不再由框架提供。**可用性没丢**：
 *     每一行都是可聚焦的（`tabindex="0"`）并响应回车/空格。
 *   - 折叠态的子菜单不做弹出层，改成**平铺成图标**（见模板里的注释）——
 *     弹出层要自己写定位与外部点击关闭，而平铺反而更容易点到。
 *   - 展开状态不再记忆（刷新后收起）。要记忆的话加一个 localStorage 即可。
 *
 * ⚠️ 高亮**不跟路由走**（这点很关键）：
 *   `selected` 是本组件的状态，只在点击时改。不去 watch `activeKey` ——
 *   目标页是懒加载的，首次进入要下载 chunk（几百毫秒），
 *   跟路由走就会出现"点了没反应，点下一个时上一个才亮"。
 */

const props = defineProps<{
  collapsed: boolean
  ready: boolean
  /** 初始高亮（刷新页面 / 直接输 URL 进来时，得知道该亮哪一行） */
  activeKey: string
  menus: AdminMenu[]
}>()

const emit = defineEmits<{
  select: [key: string]
  logout: []
}>()

/**
 * 当前高亮的那一行。**本组件自己的状态**，不由 props 派生。
 *
 * 只在挂载时用 `activeKey` 作为初始值（这样刷新后高亮是对的），
 * 之后完全由点击驱动。`watch` 那个 `activeKey` 是**兜底**，见下面的注释。
 */
const selected = ref(props.activeKey)

/** 展开了哪些分组（存 key） */
const expanded = ref<string[]>([])

/** 有子项的分组 key */
const groupKeys = computed(() =>
  props.menus.filter((menu) => menu.children?.length).map((menu) => menu.key),
)

/** 某个分组下是否有子项正被选中（用于父行的弱高亮） */
function hasActiveChild(menu: AdminMenu): boolean {
  return menu.children?.some((child) => child.key === selected.value) ?? false
}

function toggleGroup(key: string): void {
  expanded.value = expanded.value.includes(key)
    ? expanded.value.filter((item) => item !== key)
    : [...expanded.value, key]
}

/**
 * 点一行。
 *
 * 有子项的分组：只做展开/收起（它本身不是一个页面，点它不该"变蓝"）。
 * 普通项：**立刻改 `selected`（这一行马上变蓝）**，然后再把 key 抛给父组件推路由。
 * 顺序是刻意的：先保证"点了就亮"，路由慢不影响观感。
 */
function onClick(menu: AdminMenu): void {
  if (menu.children?.length) {
    toggleGroup(menu.key)
    return
  }

  selected.value = menu.key
  emit('select', menu.key)
}

/**
 * 兜底同步：**只在外部把高亮改走时**才跟随。
 *
 * 唯一的真实场景是"通过顶部页签切模块"——那时用户没点侧栏，
 * 高亮得跟着页签走，否则侧栏和页签会各说各话。
 *
 * 为什么不用 `watch(activeKey)` 直接赋值：那样会在**路由落地时**覆盖用户
 * 刚点的那一行，正是我们要避免的现象（点击 → 路由慢 → 高亮被旧值改回去）。
 * 所以加一个判断：只有当前高亮**不是**由点击造成的、且确实和外部值不同才跟随。
 * 实现上就是"点击时记一笔 pending，路由落地后清掉"。
 */
let pendingKey: string | null = null

watch(
  () => props.activeKey,
  (next) => {
    if (pendingKey === next) {
      // 这正是刚才点的那一项，路由落地了而已，什么都不用做
      pendingKey = null
      return
    }

    if (next && next !== selected.value) {
      selected.value = next
    }
  },
)

// 记下"刚点的那一项"，供上面的 watch 判断
watch(selected, (next) => {
  pendingKey = next
})

/** 折叠态下也让分组可见（子项平铺成图标）——否则折叠后有一整个分组点不到 */
function showChildren(menu: AdminMenu): boolean {
  return props.collapsed || expanded.value.includes(menu.key)
}
</script>

<template>
  <div class="side-inner" :class="{ 'is-ready': ready, 'is-collapsed': collapsed }">
    <div class="side-head">
      <span class="brand-mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3">
          <path d="M12 2.6 21.4 12 12 21.4 2.6 12Z" />
          <path d="M12 7.4 16.6 12 12 16.6 7.4 12Z" fill="currentColor" stroke="none" />
        </svg>
      </span>
      <span class="brand-text">
        <span class="brand-name">南门拈星</span>
        <span class="brand-sub">MANAGEMENT CONSOLE</span>
      </span>
    </div>

    <!--
      role="menu" + 每行 role="menuitem"：锚点式的语义，屏幕阅读器能念对。
      键盘可达性靠每行的 tabindex + @keydown.enter/.space（不另外实现上下键）。
    -->
    <nav class="menu" role="menu">
      <template v-for="(menu, index) in menus" :key="menu.key">
        <!-- 分组：父行只负责展开/收起；子项才是可选项 -->
        <div v-if="menu.children?.length" class="group" :style="{ '--i': index }">
          <div
            class="row is-group"
            :class="{ 'is-open': expanded.includes(menu.key), 'has-active': hasActiveChild(menu) }"
            role="menuitem"
            tabindex="0"
            @click="toggleGroup(menu.key)"
            @keydown.enter="toggleGroup(menu.key)"
            @keydown.space.prevent="toggleGroup(menu.key)"
          >
            <NavIcon :name="menu.icon" class="icon" />
            <template v-if="!collapsed">
              <span class="text">{{ menu.title }}</span>
              <span class="arrow" :class="{ 'is-open': expanded.includes(menu.key) }" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
                  <path d="m9 6 6 6-6 6" />
                </svg>
              </span>
            </template>
          </div>

          <!--
            折叠态：**子项平铺成图标**（不做弹出层）。
            弹出层要自己写定位、外部点击关闭、边界翻转，而折叠态本来就是
            "腾出横向空间"的用法 —— 平铺一排图标反而更容易点到，也少一套要维护的交互。
          -->
          <div v-if="showChildren(menu)" class="children" :class="{ 'is-flat': collapsed }">
            <div
              v-for="child in menu.children"
              :key="child.key"
              class="row is-child"
              :class="{ 'is-active': selected === child.key }"
              role="menuitem"
              tabindex="0"
              @click="onClick(child)"
              @keydown.enter="onClick(child)"
              @keydown.space.prevent="onClick(child)"
            >
              <NavIcon :name="child.icon" class="icon" />
              <span v-if="!collapsed" class="text">{{ child.title }}</span>
            </div>
          </div>
        </div>

        <!-- 普通项 -->
        <div
          v-else
          class="row"
          :class="{ 'is-active': selected === menu.key }"
          :style="{ '--i': index }"
          role="menuitem"
          tabindex="0"
          @click="onClick(menu)"
          @keydown.enter="onClick(menu)"
          @keydown.space.prevent="onClick(menu)"
        >
          <NavIcon :name="menu.icon" class="icon" />
          <span v-if="!collapsed" class="text">{{ menu.title }}</span>
        </div>
      </template>
    </nav>

    <div class="side-foot">
      <div
        class="row is-danger"
        role="menuitem"
        tabindex="0"
        title="退出登录"
        @click="emit('logout')"
        @keydown.enter="emit('logout')"
      >
        <NavIcon name="logout" class="icon" />
        <span v-if="!collapsed" class="text">退出登录</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
/*
 * 入场分工：
 *   整条栏从左滑进来   → AdminLayout 的 .sider（位移 -100%）
 *   菜单逐条滑入       → .menu > * 那一组（用 --i 算延迟）
 * 品牌区不做单独动画：它跟着栏一起进来就够了，再加动效会和菜单抢注意力。
 */
.side-inner {
  display: flex;
  flex-direction: column;
  height: 100%;
}

/* ── 品牌 ─────────────────────────────────────────────────────────── */

.side-head {
  display: flex;
  align-items: center;
  gap: 11px;
  height: 68px;
  padding: 0 18px;
  flex: none;
  overflow: hidden;
}

.brand-mark {
  flex: none;
  width: 30px;
  height: 30px;
  display: grid;
  place-items: center;
  color: #6ee7ff;
}

.brand-mark svg {
  width: 26px;
  height: 26px;
}

.brand-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  transition: opacity 0.3s ease;
}

.brand-name {
  font-size: 14px;
  font-weight: 600;
  letter-spacing: 0.08em;
  color: #e9ecf5;
  white-space: nowrap;
}

.brand-sub {
  margin-top: 2px;
  font-size: 9px;
  letter-spacing: 0.18em;
  color: #7c849b;
  white-space: nowrap;
}

.is-collapsed .brand-text {
  opacity: 0;
}

/* ── 菜单 ─────────────────────────────────────────────────────────── */

.menu {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 4px 0;
}

/*
 * 每一行。
 *
 * 高亮**只有一条规则**：`.row.is-active`。没有 :hover 之外的任何分支 ——
 * 这正是换成手写的目的：点哪行亮哪行，链条一眼看到底。
 */
.row {
  position: relative;
  display: flex;
  align-items: center;
  gap: 10px;
  height: 42px;
  padding: 0 12px;
  margin: 2px 8px;
  border-radius: 10px;
  cursor: pointer;
  color: #a9b1c6;
  font-size: 13px;
  user-select: none;
  transition:
    background-color 0.16s ease,
    color 0.16s ease;
}

.row:hover {
  background: rgba(255, 255, 255, 0.045);
  color: #e9ecf5;
}

/* 焦点圈：键盘走到这里时能看见。用 outline 而不是 box-shadow，
   免得和选中态的背景色叠在一起显得脏 */
.row:focus-visible {
  outline: 1px solid rgba(110, 231, 255, 0.55);
  outline-offset: -1px;
}

/* ★ 选中态 —— 点哪行亮哪行，就靠这一条 */
.row.is-active {
  background: rgba(110, 231, 255, 0.14);
  color: #ffffff;
}

.row.is-active .icon {
  color: #6ee7ff;
}

/* 左侧那道青色亮条（背景色管不到的部分，自己画） */
.row.is-active::before {
  content: '';
  position: absolute;
  left: 0;
  top: 50%;
  width: 3px;
  height: 18px;
  border-radius: 0 3px 3px 0;
  background: #6ee7ff;
  transform: translateY(-50%);
}

.icon {
  flex: none;
  width: 18px;
  height: 18px;
  color: rgba(169, 177, 198, 0.92);
  transition: color 0.16s ease;
}

.row:hover .icon {
  color: #e9ecf5;
}

.text {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* ── 分组（系统设置这类有子项的） ────────────────────────────────── */

.is-group .arrow {
  flex: none;
  width: 14px;
  height: 14px;
  color: #7c849b;
  transition: transform 0.2s ease;
}

.is-group .arrow.is-open {
  transform: rotate(90deg);
}

/* 分组里有子项被选中时，父行给一个弱一点的提示（不让它像子项那样亮） */
.is-group.has-active {
  color: #e9ecf5;
}

.is-group.has-active .icon {
  color: rgba(110, 231, 255, 0.75);
}

.children {
  overflow: hidden;
}

/* 子项：左边留一道竖线表示层级，不再缩进 —— 缩进在窄侧栏里会把文字挤没 */
.is-child {
  padding-left: 26px;
  font-size: 12.5px;
}

.is-child::after {
  content: '';
  position: absolute;
  left: 16px;
  top: 8px;
  bottom: 8px;
  width: 1px;
  background: rgba(255, 255, 255, 0.07);
}

/* 子项自己的左侧亮条要让位给层级竖线 */
.is-child.is-active::before {
  left: -8px;
}

/* 折叠态：子项平铺成一列图标，居中对齐 */
.children.is-flat .is-child {
  padding-left: 0;
  justify-content: center;
  margin-left: 10px;
  margin-right: 10px;
}

.children.is-flat .is-child::after {
  display: none;
}

/* ── 折叠态 ───────────────────────────────────────────────────────── */

.is-collapsed .row {
  justify-content: center;
  padding: 0;
  margin-left: 10px;
  margin-right: 10px;
}

.is-collapsed .row.is-active::before {
  left: -10px;
}

/* ── 底部 ─────────────────────────────────────────────────────────── */

.side-foot {
  flex: none;
  padding: 6px 0 10px;
  border-top: 1px solid rgba(255, 255, 255, 0.06);
}

.is-danger:hover {
  background: rgba(255, 120, 120, 0.12);
  color: #ffb4b4;
}

.is-danger:hover .icon {
  color: #ffb4b4;
}

/*
 * 菜单逐条入场。
 *
 * 用 `--i`（模板里按 index 设的）+ calc 算延迟 —— 不再需要逐条列 nth-child。
 * 这是手写带来的实际好处之一：序号是我们自己给的，不用去猜框架渲染出的 DOM 结构
 * （之前 nth-child 会把折叠分组里的子项也算进去，得再加一层直接子选择器去挡）。
 *
 * 只做位移、不做淡入：位移从 -100% 起，起点完全在裁切区外，
 * 不需要靠透明度"藏"，也就不会出现只露半截的中间态。
 */
.side-inner.is-ready .menu > * {
  animation: nav-item-in 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
  animation-delay: calc(var(--i, 0) * 90ms);
}

@keyframes nav-item-in {
  from {
    transform: translateX(-100%);
  }
  to {
    transform: none;
  }
}

/* 关闭动效时要把子项摆回可见 —— animation: none 会让 both 的最终态一并失效 */
@media (prefers-reduced-motion: reduce) {
  .side-inner.is-ready .menu > * {
    animation: none;
    transform: none;
  }

  .brand-text {
    transition-duration: 0.01ms;
  }

  .brand-text {
    opacity: 1;
  }

  .row,
  .icon,
  .is-group .arrow {
    transition-duration: 0.01ms;
  }
}
</style>
