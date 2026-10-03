<script setup lang="ts">
import { RouterView } from 'vue-router'
import { darkTheme, dateZhCN, zhCN, type GlobalThemeOverrides } from 'naive-ui'
import PageVeil from '@/components/transition/PageVeil.vue'

/**
 * 全局主题 —— 控件视觉的唯一出处。
 *
 * 配色对齐官网调性：深空底 + 青色主色 + 低饱和描边。
 * 色值取自 face/app/assets/css/main.css 里的设计变量，两边保持一致，
 * 将来改品牌色记得两边一起改。
 *
 * ⚠️ 重要约定：**控件的视觉参数一律配在这里，页面里不要再写
 *    `:deep(.n-input) { --n-*: ... }`**。
 *    那种写法以前登录页有一份、占位页再抄一份，加到第十个表单页时
 *    必然会有几页抄歪、几页漏改，最后同一个输入框在不同页面长得不一样。
 *    页面里只允许保留**结构微调**（比如 prefix 图标的间距、自定义图标的颜色），
 *    凡属于"颜色/边框/圆角/高度/聚焦辉光"的，都归这里管。
 */
const themeOverrides: GlobalThemeOverrides = {
  common: {
    primaryColor: '#6ee7ff',
    primaryColorHover: '#8beeff',
    primaryColorPressed: '#4fd6f2',
    primaryColorSuppl: '#6ee7ff',

    bodyColor: '#06070d',
    cardColor: '#0e1120',
    modalColor: '#0e1120',
    popoverColor: '#0e1120',
    tableColor: '#0e1120',

    borderColor: 'rgba(255, 255, 255, 0.10)',
    dividerColor: 'rgba(255, 255, 255, 0.08)',

    textColorBase: '#e9ecf5',
    textColor1: '#e9ecf5',
    textColor2: '#a9b1c6',
    textColor3: '#7c849b',

    borderRadius: '10px',
    // 与 assets/main.css 的 body 保持同一个粗黑体栈，见那里的说明
    fontFamily:
      "'PingFang SC', 'HarmonyOS Sans SC', 'MiSans', 'Source Han Sans SC', 'Noto Sans SC', 'Microsoft YaHei', system-ui, -apple-system, sans-serif",
  },

  /**
   * 输入框。
   * 底色刻意比卡片更暗一档：登录页的卡片是半透明的，如果输入框也浅，
   * 背后的时钟亮点会直接穿到文字上。
   */
  Input: {
    heightLarge: '48px',
    heightMedium: '38px',
    borderRadius: '12px',

    color: 'rgba(6, 9, 18, 0.55)',
    colorFocus: 'rgba(6, 13, 26, 0.72)',

    border: '1px solid rgba(255, 255, 255, 0.10)',
    borderHover: '1px solid rgba(110, 231, 255, 0.45)',
    borderFocus: '1px solid rgba(110, 231, 255, 0.8)',

    // 聚焦时的外圈辉光 —— 全站输入框共用这一套，不用每个页面配一次
    boxShadowFocus: '0 0 0 3px rgba(110, 231, 255, 0.12), 0 0 26px -6px rgba(110, 231, 255, 0.75)',

    caretColor: '#6ee7ff',
    textColor: '#e9ecf5',
    placeholderColor: 'rgba(124, 132, 155, 0.85)',
    iconColor: 'rgba(110, 231, 255, 0.7)',
    iconColorHover: '#6ee7ff',
  },

  Button: {
    heightLarge: '46px',
    heightMedium: '38px',
    borderRadius: '10px',
    fontWeight: '600',
  },

  Checkbox: {
    fontSize: '12px',
    textColor: '#a9b1c6',
  },

  /**
   * 布局骨架。
   * 三种颜色全部设成 transparent —— 外壳自己铺了深空渐变，
   * 让 Layout 再刷一层底色只会把它盖掉。这里的职责只剩"分隔线"。
   */
  Layout: {
    color: 'transparent',
    siderColor: 'transparent',
    headerColor: 'transparent',
    siderBorderColor: 'rgba(255, 255, 255, 0.06)',
    headerBorderColor: 'rgba(255, 255, 255, 0.06)',
  },

  /**
   * 侧栏菜单。
   * 只覆写颜色和尺寸，结构（折叠态、键盘导航、子菜单）完全用框架自带的。
   */
  Menu: {
    color: 'transparent',
    itemHeight: '42px',
    borderRadius: '10px',
    fontSize: '13px',
    itemColorHover: 'rgba(255, 255, 255, 0.045)',
    itemColorActive: 'rgba(110, 231, 255, 0.14)',
    itemColorActiveHover: 'rgba(110, 231, 255, 0.18)',
    itemTextColor: '#a9b1c6',
    itemTextColorHover: '#e9ecf5',
    itemTextColorActive: '#ffffff',
    itemTextColorActiveHover: '#ffffff',
    itemIconColor: 'rgba(169, 177, 198, 0.92)',
    itemIconColorHover: '#e9ecf5',
    itemIconColorActive: '#6ee7ff',
    itemIconColorActiveHover: '#6ee7ff',
    arrowColor: '#7c849b',
  },

  /** 顶部页签（借 n-tabs 的标签栏能力） */
  Tabs: {
    tabTextColor: '#a9b1c6',
    tabTextColorHover: '#e9ecf5',
    tabTextColorActive: '#ffffff',
    tabFontWeight: '500',
    tabFontWeightActive: '600',
    tabBorderColor: 'rgba(255, 255, 255, 0.08)',
    tabColor: 'rgba(255, 255, 255, 0.028)',
    tabColorHover: 'rgba(255, 255, 255, 0.06)',
    tabBorderRadius: '9px',
    closeIconColor: 'rgba(169, 177, 198, 0.7)',
    closeIconColorHover: 'rgba(255, 255, 255, 0.95)',
    paneColor: 'transparent',
  },

  /**
   * 内容区滚动条。
   * n-layout 用 native-scrollbar=false 时会渲染 Naive 自己的滚动条，
   * 系统默认那条又粗又亮，这里压成一条细青线。
   */
  Scrollbar: {
    color: 'rgba(110, 231, 255, 0.22)',
    colorHover: 'rgba(110, 231, 255, 0.4)',
  },

  /** 指标卡（用户列表顶部那四张） */
  Statistic: {
    labelFontSize: '11.5px',
    labelTextColor: '#7c849b',
    valueFontSize: '25px',
    valueTextColor: '#e9ecf5',
  },

  /**
   * 数据表格。
   * 表格是后台里出现频率最高的组件，这里配一次全站统一：
   * 表头用极淡的底把列名和正文分开，正文行给一点悬浮高亮。
   */
  DataTable: {
    thColor: 'rgba(255, 255, 255, 0.03)',
    thTextColor: '#a9b1c6',
    thFontWeight: '600',
    thColorHover: 'rgba(110, 231, 255, 0.06)',
    tdColor: 'transparent',
    tdColorHover: 'rgba(110, 231, 255, 0.05)',
    tdTextColor: '#d5dbe8',
    borderColor: 'rgba(255, 255, 255, 0.07)',
    borderRadius: '14px',
  },
}
</script>

<template>
  <!--
    管理端外壳。

    组件本身由 unplugin-vue-components 按需引入，所以这里不 import 任何 n-* 组件。

    Provider 只留**当下真的在用的那一个**：每多挂一个 Provider，它那套完整的弹层实现
    就多进一次包。策略是"用什么加载什么"：

      n-message-provider  → useMessage（已在用）

    已经摘掉的三个，将来真要用再补回来（补一个 Provider 不需要动其它任何地方）：
      n-dialog-provider        → useDialog。注意它是**对话框**，不是抽屉（抽屉是 n-drawer）。
                                 而且像"删除前确认"这种轻量场景，用组件式的 n-popconfirm
                                 就够，连 Provider 都不用挂。
      n-notification-provider  → useNotification
      n-loading-bar-provider   → useLoadingBar
  -->
  <n-config-provider
    :theme="darkTheme"
    :theme-overrides="themeOverrides"
    :locale="zhCN"
    :date-locale="dateZhCN"
  >
    <n-message-provider>
      <RouterView />
      <!--
        换页过渡遮罩（闸门）。
        必须挂在路由出口之外 —— 它要活得比任何单个页面久，
        挂在页面里的话，页面一卸载遮罩跟着消失，过渡就断在半截。
      -->
      <PageVeil />
    </n-message-provider>
  </n-config-provider>
</template>
