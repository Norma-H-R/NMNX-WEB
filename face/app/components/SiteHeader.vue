<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

const solid = ref(false)
const headerRef = ref(null)

const nav = [
  { label: '理念', href: '#about' },
  { label: '能力', href: '#capability' },
  { label: '联系', href: '#contact' },
]

// ---------------------------------------------------------------------------
// 底衬浓度用 CSS 变量 --hdr-p 逐帧写，不走响应式（滚动里每帧都在变）。
//   0 = 页面顶部（最薄）  1 = 已滚开（稍厚）
// 走响应式等于每帧白跑一遍组件渲染，所以只有 solid 这个布尔才交给响应式。
// ---------------------------------------------------------------------------
let raf = 0

function apply() {
  raf = 0
  const y = window.scrollY
  solid.value = y > 24
  const el = headerRef.value
  if (el) el.style.setProperty('--hdr-p', Math.min(1, y / 140).toFixed(3))
}

function onScroll() {
  if (raf) return
  raf = requestAnimationFrame(apply)
}

onMounted(() => {
  apply()
  window.addEventListener('scroll', onScroll, { passive: true })
})

onBeforeUnmount(() => {
  if (raf) cancelAnimationFrame(raf)
  window.removeEventListener('scroll', onScroll)
})
</script>

<template>
  <header ref="headerRef" class="hdr" :class="{ 'is-solid': solid }">
    <div class="hdr__inner container">
      <a class="brand" href="#top">
        <svg class="brand__mark" viewBox="0 0 24 24" aria-hidden="true">
          <path
            d="M12 1.6c.6 4.9 1.7 6 6.6 6.6-4.9.6-6 1.7-6.6 6.6-.6-4.9-1.7-6-6.6-6.6 4.9-.6 6-1.7 6.6-6.6Z"
            fill="currentColor"
          />
          <circle cx="19" cy="19" r="1.5" fill="currentColor" opacity=".55" />
          <circle cx="5.5" cy="17.5" r="1" fill="currentColor" opacity=".4" />
        </svg>
        <span class="brand__name">南门拈星</span>
      </a>

      <nav class="nav">
        <a v-for="item in nav" :key="item.href" :href="item.href">{{ item.label }}</a>
      </nav>

      <a class="btn hdr__cta" href="#contact"><span>联系我们</span></a>
    </div>
  </header>
</template>

<style scoped>
.hdr {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  z-index: 50;
  /* 底衬浓度交给 --hdr-p 逐帧插值（见脚本），这里不再为它写过渡，只留底边线 */
  transition: border-color 0.5s var(--ease);
  border-bottom: 1px solid transparent;
}

.hdr.is-solid {
  border-bottom-color: var(--line);
}

.hdr__inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  height: 76px;
  /* 常驻底衬：首屏的点阵会从这一条打穿、压着品牌名与导航文字，没有底衬读不清。
     浓度由 --hdr-p 连续插值（静止 0.30/6px → 滚开 0.72/16px），只此一层。

     之前那版是两层状态切换：滚开时整条 .hdr 磨砂、这条退回全透明；回到顶部时反过来
     （整条退掉、这条重新磨砂）。于是下滑再上滑到顶，这一条就是「先变透明、再重新
     磨砂」——看着像逻辑出错。现在这条永远有底衬，只在浓度上连续变化，不存在状态切换。
     注：别把原因写成 backdrop-filter 的 none 不可插值 —— 实测 Chrome 能把
     blur(18px) ↔ none 平滑插值，跳变另有其因。 */
  background: rgba(6, 7, 13, calc(0.3 + var(--hdr-p, 0) * 0.42));
  -webkit-backdrop-filter: blur(calc(6px + var(--hdr-p, 0) * 10px))
    saturate(calc(120% + var(--hdr-p, 0) * 30%));
  backdrop-filter: blur(calc(6px + var(--hdr-p, 0) * 10px))
    saturate(calc(120% + var(--hdr-p, 0) * 30%));
  /* 玻璃的镜面高光：顶边一条内高光。这是「液态玻璃」里最出玻璃感的一笔，
     成本几乎为零，先垫上。 */
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12);
}

.brand {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  font-size: 16px;
  letter-spacing: 0.16em;
}

.brand__mark {
  width: 22px;
  height: 22px;
  color: var(--cyan);
  transition: transform 0.7s var(--ease);
}

.brand:hover .brand__mark {
  transform: rotate(90deg) scale(1.12);
}

.brand__name {
  font-weight: 500;
}

.nav {
  display: flex;
  gap: 38px;
  margin-left: auto;
  margin-right: 38px;
}

.nav a {
  position: relative;
  font-size: 14px;
  letter-spacing: 0.08em;
  color: var(--text-dim);
  transition: color 0.35s var(--ease);
}

.nav a::after {
  content: '';
  position: absolute;
  left: 0;
  bottom: -6px;
  width: 100%;
  height: 1px;
  background: var(--grad);
  transform: scaleX(0);
  transform-origin: right;
  transition: transform 0.5s var(--ease);
}

.nav a:hover {
  color: var(--text);
}

.nav a:hover::after {
  transform: scaleX(1);
  transform-origin: left;
}

.hdr__cta {
  height: 42px;
  padding: 0 22px;
  font-size: 13.5px;
}

@media (max-width: 860px) {
  .nav {
    display: none;
  }
  .hdr__cta {
    margin-left: auto;
  }
}
</style>
