<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

/**
 * 订单抽屉。
 *
 * 需求把订单从页面里挪出来了：不再占用户中心的一块卡片，改成**贴在视口右侧
 * 中间的悬浮窄条**，点开才从右边滑出抽屉 —— 订单属于低频查看的东西，
 * 常驻在版面上会一直占着地方。
 *
 * "下载"也跟着挪进来了：原来首页那张"下载"卡已经删掉，文件现在挂在**每笔订单
 * 详情下面**（点订单 → 展开详情 → 详情底部下载），跟"这东西是哪次买的"绑在一起。
 *
 * ⚠️ 订单与文件都是写死的占位数据，落地时换成订单接口返回。
 */

const orders = [
  {
    id: 'NMNX-20260312-0041',
    item: '终身授权 · 单机版',
    amount: '¥ 1,980.00',
    date: '2026-03-12',
    done: true,
    files: [
      { tag: 'MT5', name: 'NMNX_Master_EA_v7.1.ex5', meta: '主控 EA · 1.2 MB' },
      { tag: 'PDF', name: 'NMNX_快速接入手册.pdf', meta: '手册 · 1.8 MB' },
    ],
  },
  {
    id: 'NMNX-20260402-0058',
    item: '席位扩容 +1',
    amount: '¥ 480.00',
    date: '2026-04-02',
    done: true,
    files: [{ tag: 'WIN', name: 'NMNX_Follower_Setup.exe', meta: '跟随端 · 24 MB' }],
  },
  {
    id: 'NMNX-20260910-0113',
    item: '跟随端升级服务',
    amount: '¥ 220.00',
    date: '2026-09-10',
    done: false,
    files: [],
  },
]

const open = ref(false)
const openId = ref('')

// 分组：进行中（还没完成）在前，历史订单在后 ——
// 需求是"看当前订单，也能看历史订单"，所以拆成两组而不是混排
const groups = computed(() => [
  { title: '进行中', items: orders.filter((o) => !o.done) },
  { title: '历史订单', items: orders.filter((o) => o.done) },
])

function openDrawer() {
  open.value = true
}

function close() {
  open.value = false
  openId.value = ''
}

function toggle(id) {
  openId.value = openId.value === id ? '' : id
}

// 抽屉开着时锁住背后页面：main.css 里已经有 .is-locked 这条
watch(open, (v) => {
  if (typeof document === 'undefined') return
  document.body.classList.toggle('is-locked', v)
})

function onKey(e) {
  if (e.key === 'Escape' && open.value) close()
}

onMounted(() => window.addEventListener('keydown', onKey))

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  if (typeof document !== 'undefined') document.body.classList.remove('is-locked')
})
</script>

<template>
  <!--
    传送到 body：页头是 fixed + z-index 50，而页面内容挂在 main 里（main 有自己的
    z-index: 1，等于起了个堆叠上下文）。抽屉要是留在 main 内，z-index 给多高都只在
    那层里比，永远压不过页头 —— 右上角那两个导航就会一直浮在抽屉上面。
    挂到 body 之下才和页头同层比较。
    （下面是 Teleport 本体，缩进没跟着挪：不值得为它重排整段模板。）
  -->
  <Teleport to="body">
  <button
    type="button"
    class="fab"
    :class="{ 'is-hidden': open }"
    :aria-hidden="open"
    @click="openDrawer"
  >
    <svg class="fab__icon" viewBox="0 0 24 24" aria-hidden="true">
      <path
        d="M7 3.6h10A1.4 1.4 0 0 1 18.4 5v15.4l-6.4-3.6-6.4 3.6V5A1.4 1.4 0 0 1 7 3.6Z"
        fill="none"
        stroke="currentColor"
        stroke-width="1.5"
        stroke-linejoin="round"
      />
    </svg>
    <span class="fab__text">我的订单</span>
    <span class="fab__badge">{{ orders.length }}</span>
  </button>

  <Transition name="od-mask">
    <div v-if="open" class="mask" @click="close" />
  </Transition>

  <Transition name="od-panel">
    <aside v-if="open" class="panel" role="dialog" aria-modal="true" aria-label="我的订单">
      <header class="panel__head">
        <div>
          <p class="panel__eyebrow">订单</p>
          <h2 class="panel__title">我的订单</h2>
        </div>

        <button type="button" class="panel__close" aria-label="关闭" @click="close">
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

      <div v-for="g in groups" :key="g.title" class="grp">
        <h3 class="grp__title">
          {{ g.title }}
          <span class="grp__count">{{ g.items.length }}</span>
        </h3>

        <ul class="orders">
          <li v-for="o in g.items" :key="o.id" class="order" :class="{ 'is-open': openId === o.id }">
          <button
            type="button"
            class="order__row"
            :aria-expanded="openId === o.id"
            @click="toggle(o.id)"
          >
            <span class="order__main">
              <code class="order__no">{{ o.id }}</code>
              <span class="order__item">{{ o.item }}</span>
            </span>

            <span class="order__right">
              <span class="order__amount">{{ o.amount }}</span>
              <span class="badge" :class="o.done ? 'badge--on' : 'badge--wait'">
                {{ o.done ? '已完成' : '处理中' }}
              </span>
            </span>

            <svg class="order__caret" viewBox="0 0 24 24" aria-hidden="true">
              <path
                d="m6 9 6 6 6-6"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
            </svg>
          </button>

          <div class="order__body">
            <div class="order__body-in">
              <dl class="order__meta">
                <div>
                  <dt>下单时间</dt>
                  <dd>{{ o.date }}</dd>
                </div>
                <div>
                  <dt>金额</dt>
                  <dd>{{ o.amount }}</dd>
                </div>
                <div>
                  <dt>状态</dt>
                  <dd>{{ o.done ? '已完成' : '处理中' }}</dd>
                </div>
              </dl>

              <template v-if="o.files.length">
                <p class="order__label">可下载</p>
                <ul class="files">
                  <li v-for="f in o.files" :key="f.name" class="file">
                    <span class="file__tag">{{ f.tag }}</span>
                    <span class="file__main">
                      <code class="file__name">{{ f.name }}</code>
                      <span class="file__meta">{{ f.meta }}</span>
                    </span>
                    <button type="button" class="mini">下载</button>
                  </li>
                </ul>
              </template>

              <p v-else class="order__empty">这笔订单还没有可下载的文件。</p>
            </div>
          </div>
        </li>
        </ul>
      </div>

      <p class="panel__note">文件与下单时的内容对应，换机器可以重复下载。</p>
    </aside>
  </Transition>
  </Teleport>
</template>

<style scoped>
/* ---------------------------- 侧边悬浮按钮 ---------------------------- */
.fab {
  position: fixed;
  top: 50%;
  right: 0;
  /* 压在页面内容之上，但要让开换页闸门（70） */
  z-index: 40;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 14px 8px;
  border: 1px solid var(--line-strong);
  border-right: 0;
  border-radius: 14px 0 0 14px;
  background: linear-gradient(180deg, rgba(16, 20, 34, 0.94), rgba(8, 10, 18, 0.96));
  -webkit-backdrop-filter: blur(14px) saturate(140%);
  backdrop-filter: blur(14px) saturate(140%);
  color: var(--text-dim);
  font: inherit;
  font-size: 12px;
  cursor: pointer;
  transform: translateY(-50%);
  transition:
    color 0.35s var(--ease),
    border-color 0.35s var(--ease),
    transform 0.45s var(--ease),
    box-shadow 0.45s var(--ease),
    opacity 0.3s var(--ease);
}

.fab:hover {
  color: var(--text);
  border-color: rgba(110, 231, 255, 0.5);
  transform: translateY(-50%) translateX(-3px);
  box-shadow: -12px 0 34px -16px rgba(110, 231, 255, 0.85);
}

.fab.is-hidden {
  opacity: 0;
  pointer-events: none;
}

.fab__icon {
  width: 17px;
  height: 17px;
  color: var(--cyan);
}

.fab__text {
  /* 竖排：窄条贴边，不占地方 */
  writing-mode: vertical-rl;
  letter-spacing: 0.22em;
  text-indent: 0.22em;
}

.fab__badge {
  padding: 2px 6px;
  border-radius: 99px;
  background: var(--grad);
  color: #06070d;
  font-size: 11px;
  font-weight: 600;
}

/* ---------------------------- 遮罩与抽屉 ---------------------------- */
.mask {
  position: fixed;
  inset: 0;
  z-index: 62;
  background: rgba(3, 4, 9, 0.62);
  -webkit-backdrop-filter: blur(3px);
  backdrop-filter: blur(3px);
}

.panel {
  position: fixed;
  top: 0;
  right: 0;
  bottom: 0;
  z-index: 63;
  display: flex;
  flex-direction: column;
  width: min(420px, 94vw);
  padding: 22px 20px 18px;
  border-left: 1px solid var(--line-strong);
  background: linear-gradient(180deg, rgba(14, 17, 30, 0.99), rgba(7, 9, 17, 0.99));
  box-shadow: -40px 0 90px -50px rgba(0, 0, 0, 0.95);
  /* 订单多到超出视口时整块滚动（分组之后列表区不再单独 flex 撑满） */
  overflow-y: auto;
}

.panel__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--line);
}

.panel__eyebrow {
  font-size: 12px;
  letter-spacing: 0.28em;
  color: var(--muted);
}

.panel__title {
  margin-top: 6px;
  font-size: 21px;
  font-weight: 500;
  letter-spacing: 0.01em;
}

.panel__close {
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border: 1px solid var(--line-strong);
  border-radius: 50%;
  background: transparent;
  color: var(--text-dim);
  cursor: pointer;
  transition: color 0.3s var(--ease), border-color 0.3s var(--ease);
}

.panel__close svg {
  width: 14px;
  height: 14px;
}

.panel__close:hover {
  color: var(--text);
  border-color: var(--cyan);
}

.panel__note {
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid var(--line);
  font-size: 12px;
  line-height: 1.7;
  color: var(--muted);
}

/* ---------------------------- 订单列表（分组） ---------------------------- */
.grp {
  margin-top: 6px;
}

.grp__title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
  padding: 8px 0 4px;
  font-size: 11.5px;
  font-weight: 500;
  letter-spacing: 0.2em;
  color: var(--muted);
}

.grp__count {
  display: inline-grid;
  place-items: center;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: 99px;
  background: rgba(255, 255, 255, 0.06);
  font-size: 11px;
  letter-spacing: 0;
  color: var(--text-dim);
}

.orders {
  margin: 0;
  padding: 0;
  list-style: none;
}

.order {
  border-bottom: 1px solid var(--line);
}

.order__row {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 15px 0;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.order__main {
  flex: 1;
  min-width: 0;
}

.order__no {
  display: block;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 11.5px;
  letter-spacing: 0.06em;
  color: var(--muted);
}

.order__item {
  display: block;
  margin-top: 4px;
  font-size: 14px;
  transition: color 0.35s var(--ease);
}

.order__right {
  flex: none;
  display: grid;
  justify-items: end;
  gap: 6px;
}

.order__amount {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 13px;
  font-variant-numeric: tabular-nums;
  color: var(--text);
}

.order__caret {
  flex: none;
  width: 16px;
  height: 16px;
  color: var(--muted);
  transition: transform 0.45s var(--ease), color 0.35s var(--ease);
}

.order__row:hover .order__item {
  color: var(--cyan);
}

.order.is-open .order__caret {
  transform: rotate(180deg);
  color: var(--cyan);
}

/* 展开详情：同样是 0fr → 1fr，不写死高度 */
.order__body {
  display: grid;
  grid-template-rows: 0fr;
  transition: grid-template-rows 0.45s var(--ease);
}

.order.is-open .order__body {
  grid-template-rows: 1fr;
}

.order__body-in {
  overflow: hidden;
}

.order__meta {
  display: grid;
  gap: 8px;
  margin: 0 0 4px;
  padding: 2px 0 14px;
}

.order__meta div {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
}

.order__meta dt {
  font-size: 12px;
  color: var(--muted);
}

.order__meta dd {
  margin: 0;
  font-size: 13px;
  color: var(--text-dim);
}

.order__label {
  margin-bottom: 8px;
  font-size: 11.5px;
  letter-spacing: 0.16em;
  color: var(--muted);
}

.order__empty {
  padding-bottom: 14px;
  font-size: 12.5px;
  color: var(--muted);
}

/* 下载项：挂在订单详情下方 */
.files {
  display: grid;
  gap: 8px;
  margin: 0 0 16px;
  padding: 0;
  list-style: none;
}

.file {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 11px;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.025);
}

.file__tag {
  flex: none;
  min-width: 38px;
  padding: 3px 7px;
  border: 1px solid var(--line);
  border-radius: 6px;
  text-align: center;
  font-size: 10.5px;
  letter-spacing: 0.1em;
  color: var(--muted);
}

.file__main {
  flex: 1;
  min-width: 0;
}

.file__name {
  display: block;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12.5px;
  color: var(--text-dim);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.file__meta {
  display: block;
  margin-top: 3px;
  font-size: 11.5px;
  color: var(--muted);
}

.mini {
  flex: none;
  padding: 6px 13px;
  border: 1px solid var(--line-strong);
  border-radius: 99px;
  background: transparent;
  color: var(--text-dim);
  font: inherit;
  font-size: 12.5px;
  letter-spacing: 0.06em;
  cursor: pointer;
  transition: color 0.35s var(--ease), border-color 0.35s var(--ease);
}

.mini:hover {
  color: var(--text);
  border-color: var(--cyan);
}

.badge {
  display: inline-flex;
  align-items: center;
  padding: 3px 9px;
  border: 1px solid transparent;
  border-radius: 99px;
  font-size: 11.5px;
  letter-spacing: 0.08em;
}

.badge--on {
  color: var(--cyan);
  border-color: rgba(110, 231, 255, 0.38);
  background: rgba(110, 231, 255, 0.1);
}

.badge--wait {
  color: var(--gold);
  border-color: rgba(242, 209, 141, 0.38);
  background: rgba(242, 209, 141, 0.1);
}

/* ---------------------------- 开关动效 ---------------------------- */
.od-mask-enter-active,
.od-mask-leave-active {
  transition: opacity 0.35s var(--ease);
}

.od-mask-enter-from,
.od-mask-leave-to {
  opacity: 0;
}

.od-panel-enter-active {
  transition: transform 0.5s var(--ease), opacity 0.4s var(--ease);
}

.od-panel-leave-active {
  transition: transform 0.32s var(--ease-soft), opacity 0.28s var(--ease);
}

.od-panel-enter-from,
.od-panel-leave-to {
  transform: translateX(103%);
  opacity: 0.4;
}

@media (prefers-reduced-motion: reduce) {
  .fab,
  .order__item,
  .order__caret,
  .order__body,
  .mini,
  .panel__close,
  .od-mask-enter-active,
  .od-mask-leave-active,
  .od-panel-enter-active,
  .od-panel-leave-active {
    transition: none;
  }
}
</style>
