<script setup>
import { computed, onBeforeUnmount, onMounted, useId, watch } from 'vue'

/**
 * EA 表现详情弹窗。
 *
 * 从授权卡里的「详情」打开，展示：
 *   1. 盈利曲线 —— 当日累计盈亏的手写 SVG 折线（面积图），不引图表库；
 *   2. 订单明细 —— 每一笔订单的下单时间、订单号、以及它是哪个账户下的。
 *
 * 控制方式：父组件传 `lic`（授权对象，含 curve / trades / performance），
 * 为 null 时关闭。挂到 body 下（同订单抽屉），Esc 关闭、遮罩点击关闭、
 * 开着时锁住背后的页面滚动。
 */

const props = defineProps({
  lic: { type: Object, default: null },
})

const emit = defineEmits(['close'])

const isOpen = computed(() => !!props.lic)

function close() {
  if (props.lic) emit('close')
}

function onKey(e) {
  if (e.key === 'Escape' && isOpen.value) close()
}

watch(isOpen, (v) => {
  if (typeof document === 'undefined') return
  document.body.classList.toggle('is-locked', v)
})

onMounted(() => window.addEventListener('keydown', onKey))

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  if (typeof document !== 'undefined') document.body.classList.remove('is-locked')
})

// ---------------------------------------------------------------------------
// 盈利曲线：把 curve 里的点映射到 SVG 坐标（当日累计盈亏）
// ---------------------------------------------------------------------------
const uid = useId()

const W = 640
const H = 220
const PAD = { l: 10, r: 10, t: 14, b: 24 }

const chart = computed(() => {
  const c = props.lic?.curve || []
  if (c.length < 2) return null

  const values = c.map((p) => p.v)
  const min = Math.min(0, ...values)
  const max = Math.max(0, ...values)
  const range = max - min || 1
  const n = c.length

  const x = (i) => PAD.l + (i / (n - 1)) * (W - PAD.l - PAD.r)
  const y = (v) => PAD.t + (1 - (v - min) / range) * (H - PAD.t - PAD.b)

  const pts = c.map((p, i) => `${x(i).toFixed(1)},${y(p.v).toFixed(1)}`)
  const line = pts.join(' ')
  const area = `M ${pts[0]} L ${pts.slice(1).join(' L ')} L ${x(n - 1).toFixed(1)},${(H - PAD.b).toFixed(1)} L ${x(0).toFixed(1)},${(H - PAD.b).toFixed(1)} Z`

  return {
    line,
    area,
    zeroY: y(0),
    dots: c.map((p, i) => ({ x: x(i), y: y(p.v) })),
    labels: c.map((p, i) => ({ t: p.t, x: x(i) })),
  }
})

const upOrDown = (pnl) => (pnl.startsWith('-') ? 'is-down' : 'is-up')
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div v-if="isOpen" class="wrap">
        <div class="mask" @click="close" />

        <div class="dialog" role="dialog" aria-modal="true" aria-label="EA 表现详情">
          <header class="head">
            <div class="head__main">
              <p class="head__eyebrow">今日表现</p>
              <h3 class="head__title">{{ lic.product }}</h3>
              <p class="head__sub">{{ lic.device.os }} · {{ lic.device.id }}</p>
            </div>

            <button type="button" class="close" aria-label="关闭" @click="close">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path
                  d="m6 6 12 12M18 6 6 18"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.6"
                  stroke-linecap="round"
                />
              </svg>
            </button>
          </header>

          <!-- 盈利曲线 -->
          <div v-if="chart" class="chart">
            <svg class="chart__svg" :viewBox="`0 0 ${W} ${H}`" aria-hidden="true">
              <defs>
                <linearGradient :id="`curve-${uid}`" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0" stop-color="rgba(110,231,255,0.30)" />
                  <stop offset="1" stop-color="rgba(110,231,255,0)" />
                </linearGradient>
              </defs>

              <!-- 零轴 -->
              <line
                :x1="PAD.l"
                :x2="W - PAD.r"
                :y1="chart.zeroY"
                :y2="chart.zeroY"
                class="chart__zero"
              />

              <path :d="chart.area" :fill="`url(#curve-${uid})`" />

              <polyline :points="chart.line" class="chart__line" />

              <circle
                v-for="(d, i) in chart.dots"
                :key="i"
                :cx="d.x"
                :cy="d.y"
                r="3"
                class="chart__dot"
              />
            </svg>

            <div class="chart__axis">
              <span
                v-for="(l, i) in chart.labels"
                :key="i"
                class="chart__label"
                :style="{ left: (l.x / W) * 100 + '%' }"
              >
                {{ l.t }}
              </span>
            </div>
          </div>

          <!-- 订单明细 -->
          <div class="trades">
            <div class="trades__head">
              <span>时间</span>
              <span>订单号</span>
              <span>账户</span>
              <span>品种 · 方向</span>
              <span class="trades__num">盈亏</span>
            </div>

            <div v-for="t in lic.trades" :key="t.id" class="trades__row">
              <span class="mono">{{ t.time }}</span>
              <code>{{ t.id }}</code>
              <span class="mono">{{ t.account }}</span>
              <span class="trades__dir">
                {{ t.symbol }} · {{ t.side === 'buy' ? '多' : '空' }}
              </span>
              <span class="trades__num" :class="upOrDown(t.pnl)">{{ t.pnl }}</span>
            </div>
          </div>

          <footer class="foot">
            共 {{ lic.performance.winTrades + lic.performance.lossTrades }} 笔 ·
            盈利 {{ lic.performance.winTrades }} · 亏损 {{ lic.performance.lossTrades }} ·
            今日盈亏 {{ lic.performance.pnl }}
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.wrap {
  position: fixed;
  inset: 0;
  z-index: 64;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.mask {
  position: absolute;
  inset: 0;
  background: rgba(3, 4, 9, 0.66);
  -webkit-backdrop-filter: blur(4px);
  backdrop-filter: blur(4px);
}

.dialog {
  position: relative;
  width: min(660px, 96vw);
  max-height: min(86vh, 760px);
  padding: 22px;
  border: 1px solid var(--line-strong);
  border-radius: 20px;
  background: linear-gradient(180deg, rgba(15, 18, 31, 0.99), rgba(7, 9, 17, 0.99));
  box-shadow:
    0 50px 120px -50px rgba(0, 0, 0, 0.95),
    0 0 0 1px rgba(110, 231, 255, 0.05);
  overflow-y: auto;
}

.head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--line);
}

.head__eyebrow {
  font-size: 12px;
  letter-spacing: 0.28em;
  color: var(--muted);
}

.head__title {
  margin-top: 6px;
  font-size: 20px;
  font-weight: 500;
  letter-spacing: 0.01em;
}

.head__sub {
  margin-top: 6px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12px;
  letter-spacing: 0.04em;
  color: var(--muted);
}

.close {
  display: grid;
  place-items: center;
  flex: none;
  width: 32px;
  height: 32px;
  border: 1px solid var(--line-strong);
  border-radius: 50%;
  background: transparent;
  color: var(--text-dim);
  cursor: pointer;
  transition: color 0.3s var(--ease), border-color 0.3s var(--ease);
}

.close svg {
  width: 14px;
  height: 14px;
}

.close:hover {
  color: var(--text);
  border-color: var(--cyan);
}

/* ---------------------------- 盈利曲线 ---------------------------- */
.chart {
  position: relative;
  margin-top: 18px;
}

.chart__svg {
  display: block;
  width: 100%;
  height: auto;
}

.chart__zero {
  stroke: rgba(255, 255, 255, 0.1);
  stroke-width: 1;
  stroke-dasharray: 3 4;
}

.chart__line {
  fill: none;
  stroke: var(--cyan);
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
  filter: drop-shadow(0 0 8px rgba(110, 231, 255, 0.5));
}

.chart__dot {
  fill: #06070d;
  stroke: var(--cyan);
  stroke-width: 1.6;
}

.chart__axis {
  position: relative;
  height: 16px;
  margin-top: 4px;
}

.chart__label {
  position: absolute;
  transform: translateX(-50%);
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 10.5px;
  letter-spacing: 0.04em;
  color: var(--muted);
}

/* ---------------------------- 订单明细 ---------------------------- */
.trades {
  margin-top: 16px;
}

.trades__head,
.trades__row {
  display: grid;
  grid-template-columns: 74px 118px 92px minmax(0, 1fr) 86px;
  gap: 12px;
  align-items: center;
}

.trades__head {
  padding: 8px 0;
  font-size: 11.5px;
  letter-spacing: 0.14em;
  color: var(--muted);
}

.trades__row {
  padding: 10px 0;
  border-top: 1px solid var(--line);
  font-size: 13px;
}

.trades__row code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12px;
  letter-spacing: 0.04em;
  color: var(--text-dim);
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12.5px;
  letter-spacing: 0.04em;
  font-variant-numeric: tabular-nums;
  color: var(--text-dim);
}

.trades__dir {
  color: var(--muted);
}

.trades__num {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 13px;
  font-variant-numeric: tabular-nums;
  text-align: right;
}

.trades__num.is-up {
  color: var(--cyan);
}

.trades__num.is-down {
  color: #ff9b9b;
}

.foot {
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid var(--line);
  font-size: 12.5px;
  color: var(--muted);
}

/* ---------------------------- 开关动效 ---------------------------- */
.modal-enter-active {
  transition: opacity 0.4s var(--ease);
}

.modal-leave-active {
  transition: opacity 0.24s var(--ease);
}

.modal-enter-active .dialog {
  transition: transform 0.5s var(--ease), opacity 0.4s var(--ease);
}

.modal-leave-active .dialog {
  transition: transform 0.24s var(--ease-soft), opacity 0.2s var(--ease);
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-from .dialog,
.modal-leave-to .dialog {
  transform: translateY(16px) scale(0.98);
  opacity: 0;
}

@media (max-width: 560px) {
  .trades__head {
    display: none;
  }

  .trades__row {
    grid-template-columns: minmax(0, 1fr) auto;
    row-gap: 3px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .close,
  .modal-enter-active,
  .modal-leave-active,
  .modal-enter-active .dialog,
  .modal-leave-active .dialog {
    transition: none;
  }
}
</style>
