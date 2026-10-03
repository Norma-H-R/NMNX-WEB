import Vue from 'vue'
import App from './App.vue'
import router from './router'
import store from './store'
// 上游用 CDN 引 font-awesome（cdn.bootcss.com 这个域名早已废弃且不可达），
// 改成从 node_modules 本地引，图标才不会掉。
import 'font-awesome/css/font-awesome.css'

Vue.config.productionTip = false

new Vue({
  router,
  store,
  render: h => h(App)
}).$mount('#app')
