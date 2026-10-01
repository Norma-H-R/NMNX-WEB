import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'
import Components from 'unplugin-vue-components/vite'
import { NaiveUiResolver } from 'unplugin-vue-components/resolvers'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vueDevTools(),

    // Naive UI 组件按需引入：模板里写 <n-button> 即可，由 NaiveUiResolver 在
    // 构建时自动补 import 语句并做 tree-shaking。
    //
    // 这里刻意没有启用 unplugin-auto-import。它靠生成 auto-imports.d.ts 提供类型，
    // 而 package.json 里 type-check 与 build-only 是并行跑的（run-p），
    // 全新克隆时 vue-tsc 会先于 d.ts 生成执行，直接报 "Cannot find name"。
    // 命令式 API（useMessage 等）手动从 naive-ui 引入即可，更稳也更显式。
    Components({
      resolvers: [NaiveUiResolver()],
      dts: 'src/components.d.ts',
    }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
})
