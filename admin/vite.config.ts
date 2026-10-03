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

    /*
     * 临时调试用：接收浏览器端 POST 过来的日志并打到终端。
     * 配合 SideNav.vue 里的 reportLog 定位"点击菜单后高亮错位"的问题，
     * 排查完连同那部分代码一起删掉。
     */
    {
      name: 'client-log-collector',
      configureServer(server) {
        server.middlewares.use('/__clientlog', (req, res) => {
          let body = ''
          req.on('data', (chunk: unknown) => {
            body += String(chunk)
          })
          req.on('end', () => {
            console.log(`\n[CLIENT] ${body}`)
            res.statusCode = 204
            res.end()
          })
        })
      },
    },
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },

  /**
   * 开发服务器端口固定 **3100**。
   *
   * 为什么必须固定：后台要跨域调 core（`http://127.0.0.1:8000`），
   * 而 core 的 CORS 白名单只放行 3000 / 3100（官网 3000、后台 3100）。
   * Vite 默认落在 5173，那样**每个请求都会被浏览器拦掉**，而且是浏览器层面拦的，
   * 后端日志里什么都看不到，排查起来很费劲。
   *
   * `strictPort`：3100 被占了就直接报错，而不是悄悄退到 3101 ——
   * 退过去 CORS 又不放行了，问题会以"登录莫名失败"的形式出现，更绕。
   */
  server: {
    port: 3100,
    strictPort: true,
  },
})
