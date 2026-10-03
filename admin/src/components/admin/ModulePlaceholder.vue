<script setup lang="ts">
import { ref } from 'vue'

/**
 * 模块占位页的公共外壳。
 *
 * 九个模块页都是"薄薄一层"（只有 defineOptions 的 name + 这个组件）。
 * 为什么不干脆让九条路由共用同一个组件：
 *   <KeepAlive :include> 是按**组件 name** 匹配的，一个通用组件被九条路由复用时
 *   名字只有一个，九个模块的缓存会互相串。所以必须九个独立的组件名，
 *   于是就成了 9 个薄文件 + 1 个公共壳。
 *
 * 下面的按钮和输入框用的是 Naive UI（n-button / n-input），不再是原生标签 ——
 * 全站控件必须只有一套：控件的视觉参数统一配在 App.vue 的 themeOverrides 里，
 * 这里只负责布局。之前用原生 <input> / <button> 是自己维护一套 focus、禁用态、
 * 校验态，和登录页的 n-input 长得不一样，属于迟早要还的债。
 *
 * 这个自检区不是装饰：计数器和输入框是用来当场验证
 * "切到别的页签再回来，状态还在"这件事真的生效了。
 */
defineProps<{ title: string }>()

const count = ref(0)
const draft = ref('')
</script>

<template>
  <section class="module">
    <header class="mod-head">
      <h1 class="mod-title">{{ title }}</h1>
      <p class="mod-sub">模块占位页 · 布局方案定了之后替换本页内容</p>
    </header>

    <div class="probe">
      <div class="probe-head">
        <span class="probe-tag">状态保持自检</span>
        <span class="probe-hint">
          切到别的模块再回来，下面的值应该还在；点顶部栏的刷新图标，值会被清空
        </span>
      </div>
      <div class="probe-body">
        <n-button type="primary" ghost @click="count += 1">点击计数 · {{ count }}</n-button>
        <n-input v-model:value="draft" class="probe-input" placeholder="随便输点字，然后切走再回来" />
      </div>
    </div>

    <div class="frame">
      <div class="frame-grid" aria-hidden="true"></div>
      <p class="frame-text">{{ title }} · 待布局</p>
    </div>
  </section>
</template>

<style scoped>
.module {
  max-width: 1080px;
}

.mod-head {
  margin-bottom: 24px;
}

.mod-title {
  margin: 0;
  font-size: 22px;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: #f2f5ff;
}

.mod-sub {
  margin: 8px 0 0;
  font-size: 12.5px;
  color: #7c849b;
}

/* ── 自检区 ───────────────────────────────────────────────────────── */

.probe {
  padding: 18px 20px;
  border: 1px solid rgba(110, 231, 255, 0.16);
  border-radius: 14px;
  background: rgba(110, 231, 255, 0.035);
}

.probe-head {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 14px;
}

.probe-tag {
  padding: 3px 9px;
  border: 1px solid rgba(110, 231, 255, 0.3);
  border-radius: 999px;
  font-size: 10.5px;
  font-weight: 600;
  letter-spacing: 0.12em;
  color: #6ee7ff;
}

.probe-hint {
  font-size: 12px;
  color: #7c849b;
}

/* 这个布局容器只负责排布，控件本身长什么样全由 themeOverrides 决定 */
.probe-body {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.probe-input {
  flex: 1;
  min-width: 220px;
}

/* ── 待布局区 ─────────────────────────────────────────────────────── */

.frame {
  position: relative;
  display: grid;
  place-items: center;
  height: 300px;
  margin-top: 20px;
  border: 1px dashed rgba(255, 255, 255, 0.1);
  border-radius: 14px;
  overflow: hidden;
}

.frame-grid {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgba(110, 231, 255, 0.045) 1px, transparent 1px),
    linear-gradient(90deg, rgba(110, 231, 255, 0.045) 1px, transparent 1px);
  background-size: 40px 40px;
  -webkit-mask-image: radial-gradient(90% 70% at 50% 50%, #000 20%, transparent 78%);
  mask-image: radial-gradient(90% 70% at 50% 50%, #000 20%, transparent 78%);
}

.frame-text {
  position: relative;
  margin: 0;
  font-size: 11px;
  font-weight: 500;
  letter-spacing: 0.28em;
  text-transform: uppercase;
  color: rgba(124, 132, 155, 0.75);
}
</style>
