<script setup lang="ts">
import { RouterView } from 'vue-router'
import { darkTheme, dateZhCN, zhCN, type GlobalThemeOverrides } from 'naive-ui'

/**
 * 主题对齐官网调性：深空底 + 青色主色 + 低饱和描边。
 * 色值取自 face/app/assets/css/main.css 里的设计变量，两边保持一致，
 * 将来改品牌色记得两边一起改。
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
    inputColor: 'rgba(255, 255, 255, 0.04)',

    borderColor: 'rgba(255, 255, 255, 0.10)',
    dividerColor: 'rgba(255, 255, 255, 0.08)',

    textColorBase: '#e9ecf5',
    textColor1: '#e9ecf5',
    textColor2: '#a9b1c6',
    textColor3: '#7c849b',

    borderRadius: '10px',
    fontFamily:
      "Inter, 'PingFang SC', 'Microsoft YaHei', 'Hiragino Sans GB', system-ui, sans-serif",
  },
}
</script>

<template>
  <!--
    管理端外壳。

    组件本身由 unplugin-vue-components 按需引入，所以这里不 import 任何 n-* 组件。
    下面四个 Provider 是命令式 API 的前提，缺哪个对应的 API 就会报错：
      n-message-provider       → useMessage
      n-dialog-provider        → useDialog
      n-notification-provider  → useNotification
      n-loading-bar-provider   → useLoadingBar
  -->
  <n-config-provider
    :theme="darkTheme"
    :theme-overrides="themeOverrides"
    :locale="zhCN"
    :date-locale="dateZhCN"
  >
    <n-loading-bar-provider>
      <n-dialog-provider>
        <n-notification-provider>
          <n-message-provider>
            <RouterView />
          </n-message-provider>
        </n-notification-provider>
      </n-dialog-provider>
    </n-loading-bar-provider>
  </n-config-provider>
</template>
