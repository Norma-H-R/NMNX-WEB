<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'

// 二维码走模块引用而不是 public 绝对路径：
// public 里的文件会被原样拷贝并用 /qr.png 引用，站点一旦部署在子路径下
// 就会 404。交给打包器处理成带 hash 的资源引用才安全。
import qrUrl from '~/assets/qr.png'

/**
 * 联系区块 —— 左流程 / 右联系卡（二维码可切换）。
 *
 * 构成（全部是站内已有的语汇，不引新依赖）：
 *   头部       eyebrow + title（em 走 --grad 渐变）+ lead，与其它区块同一套；
 *   左侧流程   三行步骤，行间 1px 细线；悬停时一条青紫渐变色条从左往右**横向划过整行**，
 *              同时整行文字翻成深色 —— 亮度差拉满，是和按钮同一套的强对比手法；
 *   右侧联系卡 玻璃面板 + 点阵底纹（呼应首屏那块点阵）+ 青辉；二维码是一组可切换的
 *              轮播（见下方 tabs），下方挂三个选项，再下面是当前项的值与「复制」。
 *
 * ⚠️ tabs 里只有第 1 项是真实素材（qr.png + XP863131）。
 *    第二个微信与 Telegram 我这边没有二维码和账号，先做成明确的占位态：
 *    虚线框写「二维码待补充」、值写「待补充」、复制按钮置灰。
 *    补素材时只改这个数组即可，模板与样式都不用动。
 *
 * 没做自动轮播：二维码自动切走会打断正在扫的人。要自动我再加。
 */

const tabs = [
  {
    key: 'wx1',
    label: '微信',
    hint: '扫码添加微信',
    field: '微信号',
    value: 'XP863131',
    qr: qrUrl,
    copyable: true,
  },
  {
    key: 'wx2',
    label: '微信',
    hint: '扫码添加微信',
    field: '微信号',
    value: '待补充',
    qr: '',
    copyable: false,
  },
  {
    key: 'tg',
    label: 'Telegram',
    hint: '扫码打开 Telegram',
    field: 'Telegram',
    value: '待补充',
    qr: '',
    copyable: false,
  },
]

const active = ref(0)
const cur = computed(() => tabs[active.value])

// 左侧流程的三行。文案只用现有事实：
// 账号 / 经纪商 / 有效期发码、离线校验、一次编译全量分发。
const steps = [
  { no: '01', title: '发送信息', desc: '账号、经纪商与期望的有效期，一条消息说清即可。' },
  { no: '02', title: '生成激活码', desc: '离线校验，绑定账号 / 经纪商 / 有效期，按需发码。' },
  { no: '03', title: '交付使用', desc: '一次编译，全量分发，不需要为每个人改代码。' },
]

// ---------------------------- 复制当前项 ----------------------------
// clipboard API 只在安全上下文（https / localhost）可用，且可能被权限拒绝，
// 所以留一条 execCommand 的兜底。两条都不成，就什么都不做 —— 不假装复制成功。
const copied = ref(false)
let timer = 0

async function writeText(text) {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
      return true
    }
  } catch {
    // 落到下面的兜底
  }
  try {
    const ta = document.createElement('textarea')
    ta.value = text
    ta.setAttribute('readonly', '')
    ta.style.position = 'fixed'
    ta.style.top = '-1000px'
    document.body.appendChild(ta)
    ta.select()
    const ok = document.execCommand('copy')
    document.body.removeChild(ta)
    return ok
  } catch {
    return false
  }
}

function select(i) {
  if (i === active.value) return
  active.value = i
  // 换项时把「已复制」状态收掉，否则新项的按钮会顶着上一项的提示
  copied.value = false
  clearTimeout(timer)
}

async function copyValue() {
  if (!cur.value.copyable) return
  if (!(await writeText(cur.value.value))) return
  copied.value = true
  clearTimeout(timer)
  timer = window.setTimeout(() => (copied.value = false), 1600)
}

onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <section id="contact" class="section contact">
    <div class="container">
      <div class="contact__head" v-reveal="{ selector: '.rv', stagger: 0.1 }">
        <p class="eyebrow rv">联系</p>
        <h2 class="title rv">要一个自己的<em>激活码</em>？</h2>
        <p class="lead rv">
          把账号、经纪商和期望的有效期发过来，我们生成对应的离线激活码。
        </p>
      </div>

      <div class="contact__body">
        <ol class="steps" v-reveal="{ selector: '.steps__item', stagger: 0.09, y: 28 }">
          <li v-for="s in steps" :key="s.no" class="steps__item">
            <span class="steps__no">{{ s.no }}</span>
            <div class="steps__main">
              <h3 class="steps__title">{{ s.title }}</h3>
              <p class="steps__desc">{{ s.desc }}</p>
            </div>
          </li>
        </ol>

        <aside class="ccard" v-reveal="{ y: 40, duration: 1.1 }">
          <span class="ccard__dots" aria-hidden="true" />
          <span class="ccard__glow" aria-hidden="true" />

          <figure class="ccard__qr">
            <Transition name="q-swap" mode="out-in">
              <img
                v-if="cur.qr"
                :key="cur.key"
                :src="cur.qr"
                :alt="`${cur.label}二维码`"
                width="279"
                height="279"
                loading="lazy"
              />
              <div v-else :key="cur.key" class="ccard__empty">
                <span>二维码待补充</span>
              </div>
            </Transition>
          </figure>

          <div class="ccard__tabs" role="tablist" aria-label="联系方式">
            <button
              v-for="(t, i) in tabs"
              :key="t.key"
              type="button"
              role="tab"
              class="ccard__tab"
              :class="{ 'is-active': i === active }"
              :aria-selected="i === active"
              @click="select(i)"
            >
              {{ t.label }}
            </button>
          </div>

          <p class="ccard__hint">{{ cur.hint }}</p>

          <div class="ccard__id">
            <span class="ccard__label">{{ cur.field }}</span>
            <code class="ccard__val">{{ cur.value }}</code>
            <button
              type="button"
              class="ccard__copy"
              :class="{ 'is-done': copied }"
              :disabled="!cur.copyable"
              :aria-label="cur.copyable ? `复制${cur.field} ${cur.value}` : `${cur.field}待补充`"
              @click="copyValue"
            >
              {{ cur.copyable ? (copied ? '已复制' : '复制') : '待补充' }}
            </button>
          </div>
        </aside>
      </div>
    </div>
  </section>
</template>

<style scoped>
/* 区块底光：只在右下角渗一层极淡的青，把视线带到那张卡上。
   用 background-image 而不是定位的 ::before —— 背景永远在内容之下，
   不会再出现"发光层压住文字"的层级问题。 */
.contact {
  background-image: radial-gradient(closest-side, rgba(110, 231, 255, 0.09), transparent);
  background-repeat: no-repeat;
  background-position: right bottom;
  background-size: 68% 58%;
}

/* ---------------------------- 头部 ---------------------------- */
.contact__head {
  max-width: 620px;
}

/* ---------------------------- 主体：左流程 / 右联系卡 ---------------------------- */
.contact__body {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(270px, 340px);
  gap: clamp(28px, 4vw, 76px);
  align-items: start;
  margin-top: clamp(40px, 5vw, 72px);
}

/* ---------------------------- 左侧流程 ---------------------------- */
.steps {
  margin: 0;
  padding: 0;
  list-style: none;
}

.steps__item {
  position: relative;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: clamp(16px, 2.4vw, 34px);
  padding: clamp(20px, 2.6vw, 30px) 0 clamp(20px, 2.6vw, 30px) clamp(12px, 1.6vw, 22px);
  border-top: 1px solid var(--line);
  /* 色条整行铺满，别溢出到相邻行 */
  overflow: hidden;
}

.steps__item:last-child {
  border-bottom: 1px solid var(--line);
}

/* 色条：悬停时从左往右横向划过整行。
   用 scaleX 而不是改 width —— 不引起重排，且能配合 transform-origin 控制方向。 */
.steps__item::before {
  content: '';
  position: absolute;
  inset: 0;
  background: var(--grad);
  transform: scaleX(0);
  transform-origin: left;
  transition: transform 0.62s var(--ease);
}

.steps__item:hover::before {
  transform: scaleX(1);
}

/* 文字压在色条之上，并在色条划过时整体翻成页面底色 —— 亮底深字，对比拉满 */
.steps__no,
.steps__main {
  position: relative;
}

.steps__no,
.steps__title,
.steps__desc {
  transition: color 0.45s var(--ease);
}

.steps__item:hover .steps__no,
.steps__item:hover .steps__title,
.steps__item:hover .steps__desc {
  color: #06070d;
}

.steps__no {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 13px;
  letter-spacing: 0.16em;
  color: var(--muted);
}

.steps__title {
  font-size: clamp(19px, 1.9vw, 25px);
  font-weight: 500;
  letter-spacing: 0.01em;
}

.steps__desc {
  margin-top: 10px;
  max-width: 40ch;
  font-size: clamp(14px, 1.02vw, 15.5px);
  line-height: 1.9;
  color: var(--text-dim);
}

/* ---------------------------- 右侧联系卡 ---------------------------- */
.ccard {
  position: relative;
  display: flex;
  flex-direction: column;
  padding: clamp(16px, 1.8vw, 22px);
  border: 1px solid var(--line-strong);
  border-radius: 24px;
  background: linear-gradient(160deg, rgba(20, 24, 38, 0.92), rgba(9, 11, 20, 0.97));
  box-shadow: 0 30px 80px -44px rgba(110, 231, 255, 0.5);
  overflow: hidden;
  transition: transform 0.55s var(--ease), border-color 0.55s var(--ease),
    box-shadow 0.55s var(--ease);
}

.ccard:hover {
  transform: translateY(-4px);
  border-color: rgba(110, 231, 255, 0.34);
  box-shadow: 0 42px 96px -44px rgba(110, 231, 255, 0.62);
}

/* 点阵底纹：呼应首屏那块点阵，上半部显影、往下淡出 */
.ccard__dots {
  position: absolute;
  inset: 0;
  pointer-events: none;
  background-image: radial-gradient(rgba(255, 255, 255, 0.075) 1px, transparent 1px);
  background-size: 15px 15px;
  -webkit-mask-image: radial-gradient(130% 85% at 50% -10%, #000, transparent 72%);
  mask-image: radial-gradient(130% 85% at 50% -10%, #000, transparent 72%);
}

.ccard__glow {
  position: absolute;
  top: -30%;
  right: -20%;
  width: 70%;
  height: 110%;
  pointer-events: none;
  background: radial-gradient(closest-side, rgba(110, 231, 255, 0.16), transparent);
}

/* 二维码区：白底托（扫码要有对比度），内部两项等高切换 —— 占位态与真图都不跳高度 */
.ccard__qr {
  position: relative;
  margin: 0;
  padding: 12px;
  border-radius: 16px;
  background: #fff;
}

.ccard__qr img {
  display: block;
  width: 100%;
  height: auto;
  border-radius: 7px;
}

.ccard__empty {
  display: grid;
  place-items: center;
  aspect-ratio: 1 / 1;
  border: 1px dashed rgba(6, 7, 13, 0.22);
  border-radius: 7px;
  color: #6b7280;
  font-size: 12px;
  letter-spacing: 0.14em;
}

/* 切换选项：三段式小胶囊，当前项用青紫渐变填充 */
.ccard__tabs {
  position: relative;
  display: flex;
  gap: 4px;
  margin-top: 14px;
  padding: 4px;
  border: 1px solid var(--line);
  border-radius: 99px;
  background: rgba(255, 255, 255, 0.03);
}

.ccard__tab {
  flex: 1;
  min-width: 0;
  padding: 7px 4px;
  border: 0;
  border-radius: 99px;
  background: transparent;
  color: var(--muted);
  font: inherit;
  font-size: 12.5px;
  letter-spacing: 0.04em;
  cursor: pointer;
  transition: color 0.35s var(--ease), background 0.45s var(--ease);
}

.ccard__tab:hover {
  color: var(--text);
}

.ccard__tab.is-active {
  background: var(--grad);
  color: #06070d;
}

.ccard__hint {
  position: relative;
  margin-top: 12px;
  text-align: center;
  font-size: 12px;
  letter-spacing: 0.24em;
  color: var(--muted);
}

.ccard__id {
  position: relative;
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 12px;
  padding-top: 14px;
  border-top: 1px solid var(--line);
}

.ccard__label {
  font-size: 12px;
  letter-spacing: 0.2em;
  color: var(--muted);
}

.ccard__val {
  flex: 1;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 14px;
  letter-spacing: 0.06em;
  color: var(--text);
}

.ccard__copy {
  padding: 6px 14px;
  border: 1px solid var(--line-strong);
  border-radius: 99px;
  background: transparent;
  color: var(--text-dim);
  font: inherit;
  font-size: 12.5px;
  letter-spacing: 0.06em;
  cursor: pointer;
  transition: color 0.35s var(--ease), border-color 0.35s var(--ease),
    background 0.35s var(--ease), opacity 0.35s var(--ease);
}

.ccard__copy:hover:not(:disabled) {
  color: var(--text);
  border-color: var(--cyan);
}

.ccard__copy.is-done {
  border-color: var(--cyan);
  background: var(--cyan);
  color: #06070d;
}

/* 没有真实素材的那几项：按钮置灰，不给人点了没反应的错觉 */
.ccard__copy:disabled {
  color: var(--muted);
  border-color: var(--line);
  cursor: not-allowed;
}

/* 二维码切换：旧的缩小淡出、新的放大淡入（与能力区换图同一套） */
.q-swap-enter-active {
  transition: opacity 0.32s var(--ease), transform 0.32s var(--ease);
}
.q-swap-leave-active {
  transition: opacity 0.18s var(--ease), transform 0.18s var(--ease);
}
.q-swap-enter-from {
  opacity: 0;
  transform: scale(0.94);
}
.q-swap-leave-to {
  opacity: 0;
  transform: scale(1.03);
}

/* ---------------------------- 响应式与降级 ---------------------------- */
@media (max-width: 900px) {
  .contact__body {
    grid-template-columns: minmax(0, 1fr);
  }
  /* 单列时卡片不再拉到整屏宽；二维码本身保持可扫尺寸 */
  .ccard {
    max-width: 340px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .steps__item::before,
  .steps__no,
  .steps__title,
  .steps__desc,
  .ccard,
  .ccard__tab,
  .ccard__copy {
    transition: none;
  }
  .ccard:hover {
    transform: none;
  }
  .q-swap-enter-active,
  .q-swap-leave-active {
    transition: none;
  }
}
</style>
