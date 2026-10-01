// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  // 全局设计变量与工具类，从原 Vite 工程的 src/style.css 平移过来
  css: ['~/assets/css/main.css'],

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
        { rel: 'preconnect', href: 'https://fonts.googleapis.com' },
        { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: 'anonymous' },
        {
          rel: 'stylesheet',
          href: 'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&display=swap',
        },
      ],
      // SSR 首帧遮罩。
      //
      // 站点开了服务端渲染后，HTML 一到浏览器就会先画一遍。而首屏的 GSAP 入场
      // 动画要等 JS 加载 + 注水后才跑，中间这段时间元素是"最终可见"状态，
      // 用户会看到内容先出现、再被隐藏、再滑入 —— 也就是闪烁。
      //
      // 这里在 <head> 里同步加一个类（此刻 body 还没解析，早于首次绘制），
      // 由 CSS 把首屏动效元素压成透明；等子组件挂载并写好内联样式后，
      // 再在 app.vue 里统一摘掉这个类。
      //
      // 注意：不加类就等于没有遮罩。所以禁用 JS 时内容照常可见，
      // 不执行 JS 的爬虫也能正常读到首屏文本。
      script: [{ innerHTML: "document.documentElement.classList.add('js-on')" }],
    },
  },
})
