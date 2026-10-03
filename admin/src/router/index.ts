import { createRouter, createWebHistory } from 'vue-router'
import { leafMenus } from './admin-menu'

/**
 * 路由表。
 *
 * 所有页面组件一律用动态 import —— 每个页面会被切成独立 chunk，
 * 只在真正访问时下载，首屏不需要的模块完全不进初始包。
 *
 * 路径约定：
 *   /            重定向到 /login
 *   /login       登录 / 注册
 *   /admin       控制台外壳（侧栏 + 顶部页签栏），子路由由 ADMIN_MENUS 生成
 *     /admin           概览（默认落地页）
 *     /admin/blog      博客
 *     /admin/forum     论坛
 *     /admin/articles  文章
 *     /admin/notices   公告
 *     /admin/audit     审计
 *     /admin/media     自媒体
 *     /admin/users     用户
 *     /admin/products  产品
 *
 * 子路由不在这里手写，而是从 router/admin-menu.ts 推出来 ——
 * 那个文件同时是侧栏菜单和页签标题的数据源，加模块只改那一处。
 *
 * 管理端没有匿名可看的内容，所以根路径直接落到登录页。
 * 登录守卫（未登录跳回 /login、已登录跳过 /login）等 core 的 Sanctum 接口
 * 就绪后再挂 —— 那时把 token 落到 pinia，在下面的路由上加 meta.requiresAuth 判断。
 */

// 模块增强：让 route.meta.title / icon / affix 有类型，拼错字段名时编译期就能发现
declare module 'vue-router' {
  interface RouteMeta {
    title?: string
    icon?: string
    affix?: boolean
  }
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      redirect: { name: 'login' },
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
    },
    {
      path: '/admin',
      // 外壳常驻，子路由在里面切换（也正因为常驻，页签栏才不会被切页面时重建）
      component: () => import('@/layouts/AdminLayout.vue'),
      // 只展开叶子 —— "系统设置"这种分组没有自己的页面，不该占一条路由
      children: leafMenus().map((menu) => ({
        path: menu.path,
        name: menu.key,
        component: menu.component,
        meta: { title: menu.title, icon: menu.icon, affix: menu.affix },
      })),
    },
  ],
})

export default router
