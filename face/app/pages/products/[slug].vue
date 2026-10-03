<script setup lang="ts">
import { computed, ref } from 'vue'
import { marked } from 'marked'
import { fetchProduct, fetchProducts, statusText } from '~/composables/useProduct'
import { goWithVeil } from '~/composables/usePageLink'

/**
 * 产品详情（/products/{slug}）。
 *
 * 顶部就是那块动效（`ProductHero`）：**上面是产品名、下面是版本号**，
 * 名字分 5 层错开、逐层浮起来。再往下才是介绍正文。
 *
 * ⚠️ 和博客详情一样：这里用 `v-html` 吃 marked 的输出，**只对本地假数据成立**。
 *    真实环境的正文得服务端渲染 + 白名单过滤。
 */

const route = useRoute()
const slug = computed(() => String(route.params.slug))
const product = computed(() => fetchProduct(slug.value))

const html = computed(() => {
  const p = product.value
  if (!p) return ''

  return marked.parse(p.body, { async: false }) as string
})

/** 其它产品：除了自己，取前后各几个 */
const others = computed(() => {
  const p = product.value
  if (!p) return []

  return fetchProducts().filter((x) => x.id !== p.id).slice(0, 4)
})

useHead(() => ({
  title: product.value ? `${product.value.name} v${product.value.version} · 产品` : '产品 · 南门拈星',
}))
</script>

<template>
  <section class="section">
    <BackFab fallback="/products" label="全部产品" />

    <div class="container">
      <template v-if="product">
        <!-- 顶部动效：上产品名、下版本号 -->
        <ProductHero :name="product.name" :version="product.version" :hue="product.hue" />

        <div class="meta">
          <span class="status" :class="`is-${product.status}`">{{ statusText(product.status) }}</span>
          <span v-for="t in product.tags" :key="t" class="tag">{{ t }}</span>
          <span class="meta__date">更新于 {{ product.updatedAt }}</span>
        </div>

        <div id="intro" class="prose" v-html="html" />

        <!-- 其它产品 -->
        <div v-if="others.length" class="more">
          <h2 class="more__title">其它产品</h2>
          <ul class="more__list">
            <li v-for="o in others" :key="o.id">
              <a
                :href="`/products/${o.slug}`"
                @click="goWithVeil($event, `/products/${o.slug}`)"
              >
                <span class="more__name">{{ o.name }}</span>
                <span class="more__ver">v{{ o.version }}</span>
              </a>
            </li>
          </ul>
        </div>
      </template>

      <template v-else>
        <p class="eyebrow">产品</p>
        <h1 class="title">没有找到<em>这个产品</em></h1>
        <p class="lead">链接可能拼错了，或者它已经下线了。</p>

        <p class="acts">
          <a class="btn" href="/products" @click="goWithVeil($event, '/products')">
            <span>看看全部产品</span>
          </a>
        </p>
      </template>
    </div>
  </section>
</template>

<style scoped>
.meta {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  padding-bottom: clamp(18px, 2vw, 26px);
  border-bottom: 1px solid var(--line);
}

.status {
  padding: 3px 10px;
  border: 1px solid var(--line);
  border-radius: 99px;
  font-size: 11.5px;
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

.tag {
  padding: 3px 9px;
  border: 1px solid var(--line);
  border-radius: 6px;
  font-size: 11.5px;
  color: var(--muted);
}

.meta__date {
  margin-left: auto;
  font-family: var(--mono);
  font-size: 11.5px;
  color: var(--muted);
}

/* ---------------------------- 正文 ---------------------------- */
.prose {
  max-width: 760px;
  margin-top: clamp(24px, 3vw, 36px);
  font-size: 15px;
  line-height: 1.95;
  color: var(--text-dim);
}

.prose :deep(h2) {
  margin: 32px 0 12px;
  font-size: 18px;
  font-weight: 500;
  color: var(--text);
}

.prose :deep(p) {
  margin: 13px 0;
}

.prose :deep(ul) {
  margin: 13px 0;
  padding-left: 22px;
}

.prose :deep(li) {
  margin: 6px 0;
}

.prose :deep(blockquote) {
  margin: 18px 0;
  padding: 12px 18px;
  border-left: 2px solid var(--cyan);
  border-radius: 0 10px 10px 0;
  background: rgba(110, 231, 255, 0.05);
  color: var(--text);
}

.prose :deep(blockquote p) {
  margin: 0;
}

.prose :deep(table) {
  width: 100%;
  margin: 18px 0;
  border-collapse: collapse;
  font-size: 13.5px;
}

.prose :deep(th),
.prose :deep(td) {
  padding: 9px 12px;
  border: 1px solid var(--line);
  text-align: left;
}

.prose :deep(th) {
  color: var(--text);
  background: rgba(255, 255, 255, 0.04);
}

/* ---------------------------- 其它产品 ---------------------------- */
.more {
  margin-top: clamp(34px, 4vw, 48px);
  padding-top: clamp(22px, 2.4vw, 30px);
  border-top: 1px solid var(--line);
}

.more__title {
  font-size: 13px;
  font-weight: 500;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  color: var(--muted);
}

.more__list {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 10px;
  margin: 16px 0 0;
  padding: 0;
  list-style: none;
}

.more__list a {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  padding: 12px 14px;
  border: 1px solid var(--line);
  border-radius: 12px;
  transition: border-color 0.35s var(--ease), background 0.35s var(--ease);
}

.more__list a:hover {
  border-color: var(--line-strong);
  background: rgba(255, 255, 255, 0.03);
}

.more__name {
  font-size: 14px;
}

.more__ver {
  font-family: var(--mono);
  font-size: 11.5px;
  color: var(--muted);
}

.acts {
  margin-top: clamp(26px, 2.8vw, 38px);
}

@media (prefers-reduced-motion: reduce) {
  .more__list a {
    transition: none;
  }
}
</style>
