import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  // GitHub Pages 把站点放在 https://<用户名>.github.io/<仓库名>/ 这个子路径下。
  // 用相对路径 './' 生成资源引用，这样不依赖仓库叫什么名字，
  // 换仓库名、绑自定义域名都不用改配置
  base: './',
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    open: true,
  },
  build: {
    // 星场是 canvas 绘制，没有大图资源，这里只是把临界值调高一点少报警告
    chunkSizeWarningLimit: 900,
  },
})
