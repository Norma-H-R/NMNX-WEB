<script setup lang="ts">
import { ref } from 'vue'
import { fetchProducts, statusText } from '~/composables/useProduct'
import { goWithVeil } from '~/composables/usePageLink'

/**
 * 产品列表（/products）—— **从上到下**一列，共 10 个。
 *
 * 为什么用纵向列表而不是卡片网格：产品之间是"同一套东西的不同部件"的关系，
 * 竖着排读起来是一条线；横着摊成网格反而像一堆互不相干的商品。
 */

const products = ref(fetchProducts())

/** 状态标签的色调：稳定=青、测试=金、规划=灰 */
const statusClass = (s: string) => `is-${s}`
</script>

<template>
  <section class="section">
    <div class="container">
      <header class="head" v-reveal="{ selector: '.rv', stagger: 0.1 }">
        <p class="eyebrow rv">产品</p>
        <h1 class="title rv">每一块都<em>单独能用</em></h1>
        <p class="lead rv">
          主控、跟随、回测、风控…… 十块拼成一套。每块都能单独跑，也能一起跑。
        </p>
      </header>

      <ol class="list">
        <li
          v-for="(p, i) in products"
          :key="p.id"
          class="row"
          :style="{ '--h': p.hue }"
          @click="goWithVeil($event, `/products/${p.slug}`)"
        >
          <!-- 整行是一个真链接：预渲染要靠 href 才能爬到各产品页，
               顺带键盘 Tab 也能逐个走 -->
          <a
            class="row__hit"
            :href="`/products/${p.slug}`"
            @click="goWithVeil($event, `/products/${p.slug}`)"
          >
          <span class="row__no">{{ String(i + 1).padStart(2, '0') }}</span>

          <div class="row__main">
            <div class="row__top">
              <h2 class="row__name">{{ p.name }}</h2>
              <span class="row__ver">v{{ p.version }}</span>
              <span class="status" :class="statusClass(p.status)">
                {{ statusText(p.status) }}
              </span>
            </div>

            <p class="row__tag">{{ p.tagline }}</p>

            <div class="row__tags">
              <span v-for="t in p.tags" :key="t" class="tag">{{ t }}</span>
              <span class="row__date">更新于 {{ p.updatedAt }}</span>
            </div>
          </div>

          <span class="row__go" aria-hidden="true">›</span>
          </a>
        </li>
      </ol>

      <p class="foot">
        想看某一块的细节？点进去，最上面是它的名字和版本号。
      </p>
    </div>
  </section>
</template>

<style scoped>
.head {
  max-width: 660px;
}

/* ---------------------------- 纵向列表 ---------------------------- */
.list {
  margin: clamp(26px, 3vw, 40px) 0 0;
  padding: 0;
  list-style: none;
  border-top: 1px solid var(--line);
}

.row {
  position: relative;
  border-bottom: 1px solid var(--line);
}

/* 整行是一个链接：布局放在 a 身上，li 只留边框和左侧那条主题色竖线 */
.row__hit {
  display: flex;
  align-items: center;
  gap: clamp(14px, 2vw, 26px);
  padding: clamp(16px, 2vw, 22px) 4px;
  transition: background 0.4s var(--ease);
}

.row__hit:hover {
  background: rgba(255, 255, 255, 0.025);
}

.row::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 2px;
  background: hsl(var(--h), 82%, 62%);
  transform: scaleY(0);
  transform-origin: top;
  transition: transform 0.45s var(--ease);
}

.row:hover {
  background: rgba(255, 255, 255, 0.025);
}

/* 悬停时左侧亮起一道该产品的主题色 —— 十个产品各一个色 */
.row:hover::before {
  transform: scaleY(1);
}

.row__no {
  flex: none;
  width: 42px;
  font-family: var(--mono);
  font-size: 13px;
  color: var(--muted);
  transition: color 0.35s var(--ease);
}

.row:hover .row__no {
  color: hsl(var(--h), 82%, 68%);
}

.row__main {
  flex: 1;
  min-width: 0;
}

.row__top {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 10px;
}

.row__name {
  font-size: clamp(16px, 1.8vw, 19px);
  font-weight: 500;
  transition: color 0.3s var(--ease);
}

.row:hover .row__name {
  color: hsl(var(--h), 82%, 72%);
}

.row__ver {
  font-family: var(--mono);
  font-size: 12px;
  color: var(--muted);
}

.status {
  padding: 2px 9px;
  border: 1px solid var(--line);
  border-radius: 99px;
  font-size: 11px;
  letter-spacing: 0.06em;
}

.status.is-stable {
  border-color: rgba(110, 231, 255, 0.35);
  color: var(--cyan);
}

.status.is-beta {
  border-color: rgba(242, 209, 141, 0.35);
  color: var(--gold);
}

.status.is-planned {
  color: var(--muted);
}

.row__tag {
  margin-top: 7px;
  font-size: 13px;
  line-height: 1.7;
  color: var(--text-dim);
}

.row__tags {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 7px;
  margin-top: 10px;
}

.tag {
  padding: 2px 8px;
  border: 1px solid var(--line);
  border-radius: 6px;
  font-size: 11px;
  color: var(--muted);
}

.row__date {
  margin-left: 4px;
  font-size: 11.5px;
  color: var(--muted);
}

.row__go {
  flex: none;
  font-size: 18px;
  line-height: 1;
  color: var(--muted);
  transition: transform 0.35s var(--ease), color 0.35s var(--ease);
}

.row:hover .row__go {
  color: hsl(var(--h), 82%, 68%);
  transform: translateX(4px);
}

.foot {
  margin-top: clamp(22px, 2.4vw, 30px);
  font-size: 12.5px;
  color: var(--muted);
}

@media (max-width: 640px) {
  .row__no {
    display: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .row,
  .row::before,
  .row__no,
  .row__name,
  .row__go {
    transition: none;
  }
}
</style>
