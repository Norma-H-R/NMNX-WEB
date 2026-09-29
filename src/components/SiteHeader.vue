<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

const solid = ref(false)

const nav = [
  { label: '理念', href: '#about' },
  { label: '能力', href: '#capability' },
  { label: '联系', href: '#contact' },
]

function onScroll() {
  solid.value = window.scrollY > 24
}

onMounted(() => {
  onScroll()
  window.addEventListener('scroll', onScroll, { passive: true })
})

onBeforeUnmount(() => window.removeEventListener('scroll', onScroll))
</script>

<template>
  <header class="hdr" :class="{ 'is-solid': solid }">
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
  transition: background 0.5s var(--ease), border-color 0.5s var(--ease),
    backdrop-filter 0.5s var(--ease);
  border-bottom: 1px solid transparent;
}

.hdr.is-solid {
  background: rgba(6, 7, 13, 0.72);
  backdrop-filter: blur(18px) saturate(150%);
  -webkit-backdrop-filter: blur(18px) saturate(150%);
  border-bottom-color: var(--line);
}

.hdr__inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  height: 76px;
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
