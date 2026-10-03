/**
 * admin 前端入口
 *
 * 除了挂载应用，这里还负责两件**必须集中做**的全局事：
 *
 *   1. **令牌失效（401）→ 回登录页**；
 *   2. **无权限（403）→ 弹窗提示 + 重拉权限列表**。
 *
 * 为什么都放这里而不是各个页面：
 *   401/403 可能发生在**任何一次请求**上（权限目录、角色清单、用户列表…）。
 *   每个页面各自处理必然漏，漏掉的那个就表现为"页面静静空着"
 *   或者"按钮一直在但一点就失败"，让人以为程序坏了。集中一处，漏不掉。
 *
 * @version 0.2.0
 * @since 2026-10-03
 */

import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { createDiscreteApi } from 'naive-ui'

import App from './App.vue'
import router from './router'
import { getToken } from './api/client'
import { loadMyPermissions } from './api/permissions'

const app = createApp(App)

app.use(createPinia())
app.use(router)

/**
 * 应用外的 dialog —— 不依赖任何组件上下文（`useMessage` 只能在 setup 里用）。
 * 专门用来弹那种"跟当前页面无关"的全局提示，比如 403。
 */
const { dialog } = createDiscreteApi(['dialog'])

/**
 * 令牌失效 → 回登录页。
 *
 * 边界/注意：
 *   1. 已经在登录页时**不要再跳**（用户正在输密码，被一次 401 弹走很烦）；
 *   2. 用 `replace` 而不是 `push`：失效的页面不该留在历史里，
 *      否则点"后退"又回到那个空列表页、再 401 一次，来回打转。
 */
window.addEventListener('nmnx:unauthenticated', () => {
  if (router.currentRoute.value.name === 'login') {
    return
  }

  void router.replace({ name: 'login' })
})

/**
 * 无权限（403）→ 弹窗 + 重拉权限列表。
 *
 * 弹窗文案**直接用后端给的**（形如"你没有「删除帖子」权限"）——
 * 带具体权限名才有排查价值，笼统的"操作失败"等于没提示。
 */
window.addEventListener('nmnx:forbidden', (event) => {
  const detail = (event as CustomEvent<string | undefined>).detail

  dialog.warning({
    title: '没有权限',
    content: detail || '你没有权限执行该操作',
    positiveText: '知道了',
  })

  // 权限可能刚刚被收走 —— 重拉一次，把已经没权限的入口收起来
  void loadMyPermissions().catch(() => {
    // 拉不到就算了：说明连"读自己的权限"这一步都没过，等下一次操作再提示
  })
})

// 刷新页面后有令牌、但内存里没有权限列表（那份是登录时给的）—— 补拉一次
if (getToken()) {
  void loadMyPermissions().catch(() => {
    // 失败不打断启动：页面照常渲染，权限判断会退化成"看不见按钮"
  })
}

app.mount('#app')
