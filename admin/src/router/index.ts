import { createRouter, createWebHistory } from 'vue-router'

/**
 * 路由表。
 *
 * 所有页面组件一律用动态 import —— 每个页面会被切成独立 chunk，
 * 只在真正访问时下载。首屏不需要的页面代码完全不进初始包。
 *
 * 加新页面时保持这个写法：component: () => import('@/views/XxxView.vue')
 */
const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      // 目前是占位页，页面清单确定后继续往这里加
      component: () => import('@/views/HomeView.vue'),
    },
  ],
})

export default router
