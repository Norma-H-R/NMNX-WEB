import type { Component } from 'vue'

/**
 * 后台模块清单 —— 侧栏菜单、子路由、页签标题的**唯一数据源**。
 *
 * 之前想的是"路由表里写一遍、侧栏菜单里再写一遍"，那样加一个模块要改两处，
 * 迟早会漏。现在改成：这里写一次，router 用它生成子路由、AdminLayout 用它渲染菜单。
 *
 * key 有两个身份，必须成对保持一致：
 *   1) 路由 name（router.push({ name }) 用它）
 *   2) 页面组件的 defineOptions({ name })，也就是 <KeepAlive :include> 的匹配名
 *
 * 支持两层结构：
 *   带 children 的是**分组**（如"系统设置"）—— 它本身没有页面、不进路由表、
 *   也不会被开成页签，只在菜单里当可展开的父项；只有叶子节点（有 component）
 *   才会生成路由。所以加一个分组下面挂几个页面，不需要动路由逻辑。
 */
export type AdminMenu = {
  key: string
  /** 相对 /admin 的路径，空串就是 /admin 本身。分组节点的 path 当前缀用 */
  path: string
  title: string
  icon: string
  /** 固定页签：不可关闭。概览作为落地页必须常驻 */
  affix?: boolean
  /** 叶子节点才有；没有它说明这是分组 */
  component?: () => Promise<Component>
  children?: AdminMenu[]
}

export const ADMIN_MENUS: AdminMenu[] = [
  {
    key: 'admin-overview',
    path: '',
    title: '概览',
    icon: 'overview',
    affix: true,
    component: () => import('@/views/admin/OverviewView.vue'),
  },
  {
    key: 'admin-blog',
    path: 'blog',
    title: '博客',
    icon: 'blog',
    component: () => import('@/views/admin/BlogView.vue'),
  },
  {
    key: 'admin-forum',
    path: 'forum',
    title: '论坛',
    icon: 'forum',
    component: () => import('@/views/admin/ForumView.vue'),
  },
  {
    key: 'admin-article',
    path: 'articles',
    title: '文章',
    icon: 'article',
    component: () => import('@/views/admin/ArticleView.vue'),
  },
  {
    key: 'admin-notice',
    path: 'notices',
    title: '公告',
    icon: 'notice',
    component: () => import('@/views/admin/NoticeView.vue'),
  },
  {
    key: 'admin-audit',
    path: 'audit',
    title: '审计',
    icon: 'audit',
    component: () => import('@/views/admin/AuditView.vue'),
  },
  {
    key: 'admin-media',
    path: 'media',
    title: '自媒体',
    icon: 'media',
    component: () => import('@/views/admin/MediaView.vue'),
  },
  {
    key: 'admin-user',
    path: 'users',
    title: '用户',
    icon: 'user',
    component: () => import('@/views/admin/UserView.vue'),
  },
  {
    key: 'admin-product',
    path: 'products',
    title: '产品',
    icon: 'product',
    component: () => import('@/views/admin/ProductView.vue'),
  },
  {
    key: 'admin-system',
    path: 'system',
    title: '系统设置',
    icon: 'settings',
    children: [
      {
        key: 'admin-system-admins',
        path: 'system/admins',
        title: '管理员设置',
        icon: 'team',
        component: () => import('@/views/admin/SystemAdminsView.vue'),
      },
    ],
  },
]

/**
 * 把菜单树拍平成叶子节点。
 * 路由表只需要叶子（分组没有页面），页签也不会因为点了一个分组而凭空多出来。
 */
export function flattenMenus(menus: AdminMenu[] = ADMIN_MENUS): AdminMenu[] {
  return menus.flatMap((menu) => (menu.children?.length ? flattenMenus(menu.children) : [menu]))
}

/** 叶子节点（确定有 component）—— 路由表用这个，TS 也能顺势收窄类型 */
export type LeafMenu = AdminMenu & { component: () => Promise<Component> }

export function leafMenus(menus: AdminMenu[] = ADMIN_MENUS): LeafMenu[] {
  return flattenMenus(menus).filter(
    (menu): menu is LeafMenu => typeof menu.component === 'function',
  )
}
