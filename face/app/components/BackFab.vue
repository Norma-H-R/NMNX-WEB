<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { transitionTo } from '~/composables/usePageVeil'

/**
 * 统一的返回按钮 —— **固定在屏幕右侧中间**。
 *
 * 为什么改到这儿：以前有的页面返回在左上、有的在左下，长页面要滚到底去找、
 * 短页面要抬头找，视线来回跳。钉在右侧中间之后，**滚到哪都够得着**。
 *
 * 交互：平时只露一个箭头贴着右边缘，鼠标经过时整块向左滑出、把「返回上一页」
 * 四个字带出来。点击走**浏览器历史**（保住来路和筛选），没有上一页时退到
 * `fallback` —— 直接输网址打开详情页的人也有路可走。
 */

const props = withDefaults(
  defineProps<{
    /** 没有历史记录（直接输网址打开）时退到哪 */
    fallback?: string
    /** 悬停时显示的文字 */
    label?: string
  }>(),
  { fallback: '/', label: '返回上一页' },
)

const canBack = ref(false)

onMounted(() => {
  // 历史里只有当前这一条时，back() 会把人踢出站外，所以先判断一下
  canBack.value = window.history.length > 1
})

const target = computed(() => (canBack.value ? null : props.fallback))

function go() {
  transitionTo(async () => {
    if (target.value) {
      await navigateTo(target.value)

      return
    }

    window.history.back()
  })
}
</script>

<template>
  <button type="button" class="fab" :aria-label="label" @click="go">
    <span class="fab__arrow" aria-hidden="true">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none">
        <path
          d="M15 5l-7 7 7 7"
          stroke="currentColor"
          stroke-width="1.8"
          stroke-linecap="round"
          stroke-linejoin="round"
        />
      </svg>
    </span>

    <span class="fab__text">{{ label }}</span>
  </button>
</template>

<style scoped>
.fab {
  position: fixed;
  /* 贴右边缘、垂直居中 —— 滚到哪都在手边 */
  top: 50%;
  right: 0;
  transform: translateY(-50%);
  z-index: 44;

  display: flex;
  align-items: center;
  gap: 0;
  padding: 12px 12px 12px 14px;
  border: 1px solid var(--line-strong);
  border-right: 0;
  border-radius: 14px 0 0 14px;
  background: linear-gradient(90deg, rgba(20, 25, 42, 0.96), rgba(10, 12, 22, 0.98));
  -webkit-backdrop-filter: blur(16px);
  backdrop-filter: blur(16px);
  box-shadow: -12px 0 40px -20px rgba(0, 0, 0, 0.9);
  color: var(--text-dim);
  font: inherit;
  cursor: pointer;

  /* 默认状态：文字宽度 0 + 透明，整块只比箭头大一点 */
  transition:
    transform 0.45s var(--ease),
    border-color 0.35s var(--ease),
    color 0.35s var(--ease),
    box-shadow 0.45s var(--ease);
}

.fab__arrow {
  display: grid;
  place-items: center;
  flex: none;
  width: 22px;
  height: 22px;
  transition: transform 0.4s var(--ease);
}

/* 文字平时收起来：max-width 过渡比 display 切换顺，鼠标扫过不会闪 */
.fab__text {
  max-width: 0;
  overflow: hidden;
  white-space: nowrap;
  font-size: 12.5px;
  letter-spacing: 0.08em;
  opacity: 0;
  transition:
    max-width 0.45s var(--ease),
    opacity 0.3s var(--ease),
    margin-left 0.45s var(--ease);
}

/* 悬停：整块向左滑出，文字带出来，描边走青色 */
.fab:hover,
.fab:focus-visible {
  transform: translateY(-50%) translateX(-6px);
  border-color: rgba(110, 231, 255, 0.5);
  color: var(--cyan);
  box-shadow: -18px 0 46px -18px rgba(0, 0, 0, 0.95);
  outline: none;
}

.fab:hover .fab__arrow,
.fab:focus-visible .fab__arrow {
  transform: translateX(-2px);
}

.fab:hover .fab__text,
.fab:focus-visible .fab__text {
  max-width: 120px;
  margin-left: 8px;
  opacity: 1;
}

/* 触屏没有 hover，直接把文字摊开 */
@media (hover: none) {
  .fab__text {
    max-width: 120px;
    margin-left: 8px;
    opacity: 1;
  }
}

@media (max-width: 520px) {
  .fab {
    padding: 10px 10px 10px 12px;
  }

  .fab__text {
    display: none;
  }
}

@media (prefers-reduced-motion: reduce) {
  .fab,
  .fab__arrow,
  .fab__text {
    transition: none;
  }
}
</style>
