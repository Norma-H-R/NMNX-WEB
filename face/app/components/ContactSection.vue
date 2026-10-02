<script setup>
import { onBeforeUnmount, ref } from 'vue'

// 二维码走模块引用而不是 public 绝对路径：
// public 里的文件会被原样拷贝并用 /qr.png 引用，站点一旦部署在子路径下
// 就会 404。交给打包器处理成带 hash 的资源引用才安全。
import qrUrl from '~/assets/qr.png'

/**
 * 联系区块 —— 左流程 / 右联系卡。
 *
 * 构成（全部是站内已有的语汇，不引新依赖）：
 *   头部       eyebrow + title（em 走 --grad 渐变）+ lead，与其它区块同一套；
 *   左侧流程   三行步骤，行间 1px 细线；悬停时行首亮起一道青线、序号转青色、
 *              右侧渗入一层极淡的青；
 *   右侧联系卡 玻璃面板 + 点阵底纹（呼应首屏那块 WebGL 点阵）+ 青辉；二维码放在
 *              白底托里（扫码需要对比度），下方是微信号与「复制」按钮。
 *
 * 文案只用现有事实：账号 / 经纪商 / 有效期发码、离线校验、一次编译全量分发。
 * 没有邮箱、Telegram 之类**无依据**的渠道；要加就把真实联系方式给我。
 */

const wechat = 'XP863131'

const steps = [
  { no: '01', title: '发送信息', desc: '账号、经纪商与期望的有效期，一条消息说清即可。' },
  { no: '02', title: '生成激活码', desc: '离线校验，绑定账号 / 经纪商 / 有效期，按需发码。' },
  { no: '03', title: '交付使用', desc: '一次编译，全量分发，不需要为每个人改代码。' },
]

// ---------------------------- 复制微信号 ----------------------------
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

async function copyWechat() {
  if (!(await writeText(wechat))) return
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
            <img :src="qrUrl" alt="微信二维码" width="279" height="279" loading="lazy" />
          </figure>

          <p class="ccard__cap">扫码添加微信</p>

          <div class="ccard__id">
            <span class="ccard__label">微信号</span>
            <code class="ccard__val">{{ wechat }}</code>
            <button
              type="button"
              class="ccard__copy"
              :class="{ 'is-done': copied }"
              :aria-label="`复制微信号 ${wechat}`"
              @click="copyWechat"
            >
              {{ copied ? '已复制' : '复制' }}
            </button>
          </div>
        </aside>
      </div>
    </div>
  </section>
</template>

<style scoped>
/* 区块底光：只在右下角渗一层极淡的青，把视线带到那张卡上。
   用 background-image 而不是 ::before 定位元素 —— 背景永远在内容之下，
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
  transition: background 0.5s var(--ease);
}

.steps__item:last-child {
  border-bottom: 1px solid var(--line);
}

/* 行首竖线：悬停时自上而下展开。用 scaleY，不引起重排 */
.steps__item::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 2px;
  background: var(--cyan);
  transform: scaleY(0);
  transform-origin: top;
  transition: transform 0.55s var(--ease);
}

.steps__item:hover::before {
  transform: scaleY(1);
}

.steps__item:hover {
  background: linear-gradient(90deg, rgba(110, 231, 255, 0.055), transparent 62%);
}

.steps__no {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 13px;
  letter-spacing: 0.16em;
  color: var(--muted);
  transition: color 0.45s var(--ease);
}

.steps__item:hover .steps__no {
  color: var(--cyan);
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

/* 二维码：白底托（扫码要有对比度），圆角与外框呼应 */
.ccard__qr {
  position: relative;
  margin: 0;
  padding: 12px;
  border-radius: 16px;
  background: #fff;
}

.ccard__qr img {
  width: 100%;
  height: auto;
  border-radius: 7px;
}

.ccard__cap {
  position: relative;
  margin-top: 14px;
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
  margin-top: 14px;
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
    background 0.35s var(--ease);
}

.ccard__copy:hover {
  color: var(--text);
  border-color: var(--cyan);
}

.ccard__copy.is-done {
  border-color: var(--cyan);
  background: var(--cyan);
  color: #06070d;
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
  .steps__item,
  .steps__item::before,
  .steps__no,
  .ccard,
  .ccard__copy {
    transition: none;
  }
  .ccard:hover {
    transform: none;
  }
}
</style>
