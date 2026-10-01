<script setup lang="ts">
import { ref } from 'vue'
import { useMessage } from 'naive-ui'

// 这只是一个占位页，用来验证 Naive UI 是否真的接通了：
//   - 模板里的 n-card / n-button / n-tag 走按需引入（见 vite.config.ts）
//   - useMessage 依赖 App.vue 里的 n-message-provider，缺了会直接报错
// 页面清单确定后，直接用真实控制台替换掉这个文件。
const message = useMessage()
const count = ref(0)

function ping() {
  count.value += 1
  message.success(`Naive UI 已接入 · 第 ${count.value} 次`)
}
</script>

<template>
  <div class="placeholder">
    <n-card title="管理端骨架就绪" :bordered="false">
      <n-space vertical size="large">
        <n-text depth="3">
          组件按需引入，命令式 API 显式导入，主题已对齐官网配色。 页面清单确定后替换本页。
        </n-text>

        <n-space align="center">
          <n-button type="primary" @click="ping">测试消息</n-button>
          <n-tag :bordered="false" type="info">Vue 3 + TypeScript + Naive UI</n-tag>
        </n-space>
      </n-space>
    </n-card>
  </div>
</template>

<style scoped>
.placeholder {
  padding: 32px;
  max-width: 720px;
}
</style>
