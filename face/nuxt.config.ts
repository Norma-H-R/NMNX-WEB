// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  // 全局设计变量与工具类，从原 Vite 工程的 src/style.css 平移过来
  css: ['~/assets/css/main.css'],

  // 后端 API 地址（core 的 php artisan serve）。
  // 部署/换环境时用环境变量覆盖，不用改代码：NUXT_PUBLIC_API_BASE=https://api.example.com
  // （Nuxt 会把 NUXT_PUBLIC_API_BASE 自动映射到 runtimeConfig.public.apiBase）
  runtimeConfig: {
    public: {
      apiBase: 'http://127.0.0.1:8000',
    },
  },

  app: {
    head: {
      htmlAttrs: { lang: 'zh-CN' },
      title: '南门拈星',
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1.0' },
        { name: 'theme-color', content: '#06070d' },
        {
          name: 'description',
          content: '南门拈星 —— 以数据与工程为底，做安静的量化交易系统。',
        },
      ],
      link: [
        { rel: 'icon', type: 'image/svg+xml', href: '/favicon.svg' },

        // 字体走自托管（见 main.css 的 @font-face），这里预加载那一个变量字体文件，
        // 避免首屏文字先以回退字体显示、再换成 Inter 时抖一下。
        // 字体属于 CORS 资源，crossorigin 必须带，否则浏览器会重复下载。
        {
          rel: 'preload',
          href: '/fonts/inter-var.woff2',
          as: 'font',
          type: 'font/woff2',
          crossorigin: 'anonymous',
        },
      ],
      // SSR 首帧遮罩。
      //
      // 站点开了服务端渲染后，HTML 一到浏览器就会先画一遍。而滚动入场的
      // v-reveal 要等 JS 加载 + 注水后才接管，中间这段时间元素是"最终可见"
      // 状态，用户会看到内容先出现、再被隐藏、再滑入 —— 也就是闪烁。
      //
      // 这里在 <head> 里同步加一个类（此刻 body 还没解析，早于首次绘制），
      // 由 CSS 把滚动入场的元素压成透明；等指令挂好 data-reveal 之后，
      // 再在 app.vue 里统一摘掉这个类。
      //
      // 不加类就等于没有遮罩。所以禁用 JS 时内容照常可见，
      // 不执行 JS 的爬虫也能正常读到首屏文本。
      //
      // 首屏 Hero 的入场动画不依赖这个类 —— 它是纯 CSS animation，
      // 靠 animation-fill-mode: backwards 在 delay 期间就保持起始态。
      script: [{ innerHTML: "document.documentElement.classList.add('js-on')" }],
    },
  },
})
