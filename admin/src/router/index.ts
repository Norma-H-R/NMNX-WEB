import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    // 目前只有一个占位首页。
    // 具体页面清单确定后再往这里加，并按需做路由级懒加载（() => import(...)）。
    {
      path: '/',
      name: 'home',
      component: HomeView,
    },
  ],
})

export default router
