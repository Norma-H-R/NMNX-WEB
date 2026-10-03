import { defineStore } from 'pinia'
import { nextTick, ref } from 'vue'
import type { RouteLocationNormalizedLoaded } from 'vue-router'

/**
 * 顶部页签（多开模块）的状态机。
 *
 * 这里的每一件事都围绕一个约束：**页签 = 一份要留下来的现场**。
 * 所以它管三样东西：
 *   items    开着的页签（含标题/图标/是否固定）
 *   cached   给 <KeepAlive :include> 用的组件名白名单 —— 只有"开着的页签"配被缓存
 *   tokens   每个页签的重载计数。改一下，那个页签的组件就会被重建 ——
 *            这就是"单独刷新某个模块"的实现，不用 location.reload() 整页重来
 *
 * 页签的 key 直接用**路由 name**，而且视图组件的 defineOptions({ name })
 * 也叫同一个字符串 —— 这样 KeepAlive 的 include 匹配、路由跳转、页签去重
 * 全都指的是同一个标识，不需要再维护一张映射表。
 */

export type TabItem = {
  key: string
  path: string
  title: string
  icon: string
  affix: boolean // 固定页签：不可关闭（概览就是）
}

export const useTabsStore = defineStore('tabs', () => {
  const items = ref<TabItem[]>([])
  const activeKey = ref('')
  const cached = ref<string[]>([])
  const tokens = ref<Record<string, number>>({})

  /** 页签的重载计数，给 <component :key> 拼 key 用 */
  function tokenOf(key: string) {
    return tokens.value[key] ?? 0
  }

  /*
   * 这里原本有一个 pending（点击后立刻要显示的项）+ displayKey，用来做乐观高亮。
   * 现在菜单是非受控的：点哪行它自己立刻变亮，不需要这层中转，所以整块去掉了。
   */

  /** 打开（或激活）一个页签 —— 路由变化时由 AdminLayout 调 */
  function open(route: RouteLocationNormalizedLoaded) {
    const key = String(route.name ?? '')
    if (!key) return

    activeKey.value = key

    const exist = items.value.find((t) => t.key === key)
    if (exist) {
      // 同一个页面带不同 query 时，页签要跟着走（比如列表页的筛选条件）
      exist.path = route.fullPath
    } else {
      items.value.push({
        key,
        path: route.fullPath,
        title: String(route.meta.title ?? '未命名'),
        icon: String(route.meta.icon ?? 'overview'),
        affix: Boolean(route.meta.affix),
      })
    }

    if (!cached.value.includes(key)) cached.value.push(key)
  }

  /**
   * 关闭一个页签。
   * 返回"接下来该激活哪个页签的 name"，调用方负责跳过去；不需要跳就返回 null。
   * 固定页签（affix）不给关。
   */
  function close(key: string): string | null {
    const index = items.value.findIndex((t) => t.key === key)
    // 项目开了 noUncheckedIndexedAccess，取下标要先落到变量上再判空
    const target = items.value[index]
    if (index < 0 || !target || target.affix) return null

    items.value.splice(index, 1)
    cached.value = cached.value.filter((name) => name !== key)

    if (activeKey.value !== key) return null

    // 优先接替右边那个，没有就退回左边（浏览器的习惯）
    const next = items.value[index] ?? items.value[index - 1]
    activeKey.value = next ? next.key : ''
    return next ? next.key : null
  }

  /** 关闭其它：固定页签永远留着 */
  function closeOthers(key: string) {
    items.value = items.value.filter((t) => t.affix || t.key === key)
    cached.value = cached.value.filter((name) => items.value.some((t) => t.key === name))
    activeKey.value = key
  }

  /**
   * 单独刷新一个页签。
   *
   * 三步走，顺序不能换：
   *   1) 先把组件名从 KeepAlive 白名单里摘掉 —— 缓存里那份旧实例就此作废
   *   2) 改重载计数 —— <component :key> 变化，强制重建一个新实例
   *   3) 再把名字放回白名单 —— 新实例重新被缓存
   * 少了第 1 步，旧实例会一直躺在缓存里占内存；少了第 3 步，刷新完就不会再被缓存了。
   */
  async function reload(key: string) {
    if (!cached.value.includes(key)) {
      tokens.value[key] = tokenOf(key) + 1
      return
    }

    cached.value = cached.value.filter((name) => name !== key)
    await nextTick()

    tokens.value[key] = tokenOf(key) + 1
    await nextTick()

    cached.value = [...cached.value, key]
  }

  return { items, activeKey, cached, tokenOf, open, close, closeOthers, reload }
})
