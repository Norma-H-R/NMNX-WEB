<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useAccount } from '~/composables/useAccount'
import { goWithVeil } from '~/composables/usePageLink'
import { useNotices } from '~/composables/useNotices'

const solid = ref(false)
const headerRef = ref(null)

// 锚点链接：在首页直接跳区块；在子页（博客 / 论坛…）要带回首页路径，
// 否则点了不动 —— 那些 #about 在子页上并不存在。
const route = useRoute()
const anchor = (h) => (route.path === '/' ? h : `/${h}`)

/*
 * 两套导航，按所在页面切。
 *
 * 首页：滚到页面里的区块，用锚点；
 * 子页（用户中心、通知页…）：#about 这些锚点在子页上根本不存在，留着点了不动，
 *   所以换成站内页面之间的导航 —— 博客 / 论坛 / 产品介绍 / 文章。
 */
const NAV_HOME = [
  { label: '理念', href: '#about' },
  { label: '能力', href: '#capability' },
  { label: '联系', href: '#contact' },
]

const NAV_PAGE = [
  { label: '博客', href: '/blog' },
  { label: '论坛', href: '/forum' },
  { label: '产品介绍', href: '/products' },
  { label: '文章', href: '/articles' },
]

const onHome = computed(() => route.path === '/')
const nav = computed(() => (onHome.value ? NAV_HOME : NAV_PAGE))

/** 子页那套是站内链接，走闸门过渡；首页的锚点交给浏览器原生滚动 */
function onNav(e, item) {
  if (item.href.startsWith('#')) return

  goWithVeil(e, item.href)
}

// ---------------------------------------------------------------------------
// 进用户中心走闸门过渡（"大刷屏"）：点一下先让两扇门合拢 + 中缝亮光刃，
// 等屏被完全遮住才真正换页 —— 跳转那一帧的闪动就被盖住了，揭开时已经是新页面。
//
// 只接管普通左键：中键 / Ctrl / Shift / Alt + 点击仍交给浏览器（新标签打开），
// href 也照常留着，禁用 JS 时链接依然能用。
// ---------------------------------------------------------------------------
function goAccount(e) {
  // 已经在用户中心就别再进一次 —— 那等于白跑一趟闸门、把页面整个重新挂载
  if (route.path === '/account') {
    e.preventDefault()

    return
  }

  goTo(e, '/account')
}

/** 积分商城：已经在用户中心就地滚过去，同样不重新进一次 */
function goPoints(e) {
  if (route.path === '/account') {
    e.preventDefault()
    document.getElementById('points')?.scrollIntoView({ behavior: 'smooth', block: 'start' })

    return
  }

  goTo(e, '/account#points')
}

/** 站内跳转统一走 usePageLink 的 goWithVeil（闸门过渡 + 只接管普通左键） */
const goTo = goWithVeil

// ---------------------------------------------------------------------------
// 账户入口：未登录是「登录/注册」四个字，已登录换成头像。
// 鼠标经过头像时头像上移、下方滑出一张卡片（时间 / 授权倒计时 / 积分）。
//
// 展开纯走 CSS :hover（见 .me:hover），没走 JS：省掉一个 hover 状态机，
// 也不会在鼠标快速划过时留下没收回去的浮层。键盘用户由 :focus-within 兜底。
// ---------------------------------------------------------------------------
const { logged, sessionReady, user, countdown, now, avatarUrl, startClock, restoreSession, logout } = useAccount()

// 未读系统公告：红点 + 数字挂在头像右下角。
// 只有系统公告走这里 —— 博客 / 论坛的回复刻意不在页头提示，要进个人中心才看得到。
const { unreadAnnouncements, unreadReplies, loadRead } = useNotices()

// 已读状态在 localStorage，服务端读不到；等客户端读完再决定要不要出红点，
// 否则服务端渲染成"有红点"、客户端变成"没有"，直接撞 hydration
const noticeReady = ref(false)

onMounted(() => {
  loadRead()
  noticeReady.value = true
})

const noticeCount = computed(() => (noticeReady.value ? unreadAnnouncements.value.length : 0))

const pad = (n) => (n < 10 ? `0${n}` : String(n))

const clockText = computed(() => {
  const d = new Date(now.value)
  return `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
})

// 手写千分位而不是 toLocaleString：后者的分隔符取决于运行环境的 locale，
// SSR 与浏览器不一致就会 hydration 报错
const pointsText = computed(() => String(user.points).replace(/\B(?=(\d{3})+(?!\d))/g, ','))

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
  if (!el) return
  // 底衬浓度：很短的斜坡就够了
  el.style.setProperty('--hdr-p', Math.min(1, y / 140).toFixed(3))
  // 收薄进度：滚满一屏（也就是到第二屏）时正好 1 → 高度 76 → 45.6px，薄了 40%
  const shrink = Math.min(1, y / (window.innerHeight || 1))
  el.style.setProperty('--hdr-shrink', shrink.toFixed(3))
  syncLensHeight(shrink)
}

function onScroll() {
  if (raf) return
  raf = requestAnimationFrame(apply)
}

// ---------------------------------------------------------------------------
// 液态玻璃（手写，不引库）
//
// 折射 = 位移贴图 + feDisplacementMap，这是各家 liquid-glass 库的共同内核
// （liquid-glass-react / liquid-svg-glass / tomagranate 都是这一套），
// 差别只在贴图怎么生成。这里是整屏通铺的横条，透镜只发生在下边缘，所以贴图
// 只需沿 y 变化、沿 x 完全一致 —— 画成 8px 宽就够，横向拉伸不丢任何信息。
//
// 贴图用 canvas 现画：R 通道保持中性（横向不动），G 通道编码纵向位移，
// 剖面的导数形状让边缘处为 0、往里 LENS_W 处最大、再往外衰减（就是一块透镜）。
// ---------------------------------------------------------------------------
const LENS_W = 14 // 透镜影响深度(px)：离边缘这么远的地方位移最大
const LENS_SCALE = 14 // feDisplacementMap 的 scale（位移 ≈ scale × 通道偏移量）
const LENS_SIGN = -1 // -1 = 往玻璃内部取样（边缘压缩感），+1 是外凸；凭眼睛定
const LENS_H_BASE = 76 // 页头静止时的高度，透镜贴图按它标定

const mapRef = ref(null)

function buildLensMap(h) {
  // 透镜深度按高度等比缩放：条变薄时透镜带也跟着变薄，玻璃边的观感才一致
  const lensW = Math.max(4, LENS_W * (h / LENS_H_BASE))
  const c = document.createElement('canvas')
  c.width = 8
  c.height = h
  const g = c.getContext('2d')
  const im = g.createImageData(8, h)
  for (let y = 0; y < h; y++) {
    const d = h - 1 - y // 距下边缘的像素数
    const t = d / lensW
    const amp = t === 0 ? 0 : t * Math.exp(1 - t) // 0 在边缘、1 在 t=1 处
    const gv = Math.max(0, Math.min(255, Math.round(127.5 + LENS_SIGN * amp * 120)))
    for (let x = 0; x < 8; x++) {
      const i = (y * 8 + x) * 4
      im.data[i] = 128 // R：中性，不横向位移
      im.data[i + 1] = gv // G：纵向位移
      im.data[i + 2] = 0
      im.data[i + 3] = 255
    }
  }
  g.putImageData(im, 0, 0)
  return c.toDataURL()
}

// ⚠️ feImage 的 x/y/width/height 必须写**像素值**，写百分比它整块不生效
//    （实测：百分比 → 上段下段位移一样，说明贴图根本没被读；像素值 → 严格按贴图
//     上下两半给出 +7 / -7）。承载滤镜的 SVG 尺寸无关，所以这里用像素值 + 元素实宽。
// 另外滤镜得等贴图挂好再启用，否则首帧会用一个空 feImage 去位移背景（会闪一下），
// 所以 CSS 里写成 var(--hdr-lens, blur(0px))，由这里赋值才生效。
function syncLens(hIn) {
  const el = headerRef.value
  const img = mapRef.value
  if (!el || !img) return
  const w = Math.round(el.getBoundingClientRect().width) || 1
  const h = Math.round(hIn || el.offsetHeight || LENS_H_BASE)
  img.setAttribute('x', '0')
  img.setAttribute('y', '0')
  img.setAttribute('width', String(w))
  img.setAttribute('height', String(h))
  img.setAttribute('href', buildLensMap(h))
  el.style.setProperty('--hdr-lens', 'url(#hdrLens)')
}

// 贴图区域是按元素实高写的（像素值，不能用百分比），而高度随滚动在变 ——
// 高度变了不同步的话，贴图会被裁掉，下边缘的折射滚起来就没了。
// 按 3px 量化重建：整屏下来七八次，不至于每帧重画 canvas。
let lensH = 0

function syncLensHeight(shrink) {
  const h = LENS_H_BASE * (1 - shrink * 0.4)
  if (Math.abs(h - lensH) < 3) return
  lensH = h
  syncLens(h)
}

function onResize() {
  syncLens()
  apply() // 收薄进度是按视口高算的，视口一变要重算
}

onMounted(() => {
  apply()
  syncLens()
  // 悬浮卡上的时间和倒计时每秒都在走：交给共享时钟（幂等，多处调用只起一个 interval）
  startClock()
  // 页头每页都在，顺手在这里恢复本地会话（幂等）——
  // 刷新后头像能立刻回来，不用先点进用户中心
  void restoreSession()
  window.addEventListener('scroll', onScroll, { passive: true })
  window.addEventListener('resize', onResize, { passive: true })
})

onBeforeUnmount(() => {
  if (raf) cancelAnimationFrame(raf)
  window.removeEventListener('scroll', onScroll)
  window.removeEventListener('resize', onResize)
})
</script>

<template>
  <header ref="headerRef" class="hdr" :class="{ 'is-solid': solid }">
    <!-- 折射滤镜。贴图由 canvas 现画（见脚本），用 backdrop-filter: url(#hdrLens) 挂上。
         0×0 + overflow:hidden，只是为了把 defs 藏起来，不参与布局。 -->
    <svg class="hdr__defs" aria-hidden="true" focusable="false">
      <filter
        id="hdrLens"
        x="0"
        y="0"
        width="100%"
        height="100%"
        color-interpolation-filters="sRGB"
      >
        <!-- x/y/width/height 由脚本按元素实宽写成像素值，别在这里写百分比 -->
        <feImage ref="mapRef" preserveAspectRatio="none" result="lens" />
        <feDisplacementMap
          in="SourceGraphic"
          in2="lens"
          :scale="LENS_SCALE"
          xChannelSelector="R"
          yChannelSelector="G"
        />
      </filter>
    </svg>

    <div class="hdr__inner container">
      <a class="brand" :href="anchor('#top')">
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
        <a
          v-for="item in nav"
          :key="item.href"
          :href="item.href.startsWith('#') ? anchor(item.href) : item.href"
          @click="onNav($event, item)"
        >
          {{ item.label }}
        </a>
      </nav>

      <!-- 账户入口。两者都指向站内的用户中心页（pages/account.vue）：
           未登录时那一页是登录/注册表单，登录后是账户面板。
           点击不直接跳，交给 goAccount 走闸门过渡（见脚本）。 -->
      <!--
        会话未确定（= 服务端渲染到注水完成这一瞬间）：留一个占位。
        不显示"登录/注册"也不显示头像 —— 否则已登录的人刷新时会先闪一下登录入口。
        占位做成与头像同尺寸的不可见方块，页头不会抖。
      -->
      <span v-if="!sessionReady" class="hdr__pending" aria-hidden="true" />

      <a v-else-if="!logged" class="hdr__login" href="/account" @click="goAccount">登录/注册</a>

      <div v-else class="me">
        <a class="me__btn" href="/account" @click="goAccount" aria-label="进入用户中心">
          <img class="me__img" :src="avatarUrl" alt="" width="34" height="34" />
          <!-- 未读系统公告：红点 + 数字（博客 / 论坛的回复不在这里提示） -->
          <span v-if="noticeCount" class="me__badge">
            {{ noticeCount > 9 ? '9+' : noticeCount }}
          </span>
        </a>

        <!--
          悬浮卡：头像上移、卡片从上方滑下来（展开全靠 CSS :hover，见样式）。
          .me__pop 自己带 padding-top 当作与头像之间的"桥" —— 鼠标从头像挪到卡上
          时不会经过一段不属于卡片的空隙，卡片就不会中途收回去。
        -->
        <div class="me__pop">
          <div class="me__card">
            <div class="me__top">
              <img class="me__avatar" :src="avatarUrl" alt="" width="40" height="40" />
              <div class="me__who">
                <p class="me__name">{{ user.name }}</p>
                <p class="me__mail">{{ user.email }}</p>
              </div>
              <span class="me__tier">{{ user.tier }}</span>
            </div>

            <ul class="me__stats">
              <li>
                <span class="me__k">当前时间</span>
                <!-- 秒级数字在 SSR 与客户端必然对不齐，包一层 ClientOnly 免得 hydration 报错 -->
                <ClientOnly>
                  <span class="me__v me__v--mono">{{ clockText }}</span>
                  <template #fallback>
                    <span class="me__v me__v--mono">--:--:--</span>
                  </template>
                </ClientOnly>
              </li>
              <li>
                <span class="me__k">最近到期</span>
                <ClientOnly>
                  <span class="me__v me__v--mono">{{ countdown.days }} 天</span>
                  <template #fallback>
                    <span class="me__v me__v--mono">-- 天</span>
                  </template>
                </ClientOnly>
              </li>
              <li>
                <span class="me__k">积分</span>
                <span class="me__v me__v--gold">{{ pointsText }}</span>
              </li>
            </ul>

            <!--
            未读：按「公告 / 通知」分两类。
            公告 = 系统公告，通知 = 博客 / 论坛的回复 —— 这里只报数，点进去才是完整列表。
            数字读的是 localStorage 的已读记录，服务端读不到，所以先给「—」占位，
            等客户端读完再填，免得撞 hydration。
          -->
          <div class="me__unread">
            <a
              class="me__un"
              href="/notices?type=announcement"
              @click="goTo($event, '/notices?type=announcement')"
            >
              <span class="me__un-k">公告</span>
              <span class="me__un-v">
                <b>{{ noticeReady ? unreadAnnouncements.length : '—' }}</b> 条未读
              </span>
              <span class="me__un-go" aria-hidden="true">›</span>
            </a>

            <a
              class="me__un"
              href="/notices?type=reply"
              @click="goTo($event, '/notices?type=reply')"
            >
              <span class="me__un-k">通知</span>
              <span class="me__un-v">
                <b>{{ noticeReady ? unreadReplies.length : '—' }}</b> 条未读
              </span>
              <span class="me__un-go" aria-hidden="true">›</span>
            </a>
          </div>

          <div class="me__acts">
              <a class="me__act" href="/account" @click="goAccount">用户中心</a>
              <a class="me__act" href="/account#points" @click="goPoints">积分商城</a>
              <button type="button" class="me__act me__act--out" @click="logout">退出登录</button>
            </div>
          </div>
        </div>
      </div>
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

.hdr__defs {
  position: absolute;
  width: 0;
  height: 0;
  overflow: hidden;
}

.hdr__inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  height: 76px;

  /* 收薄的是**高度**，不是宽度：宽度始终通铺满屏，只越滚越薄。
     --hdr-shrink 0→1 由脚本按 scrollY ÷ 视口高 写，滚满一屏（即到第二屏）正好 1，
     此时高度 76 → 45.6px，正好薄了 40%。
     横向留白固定用 --pad，与首屏下方各区对齐，不随收薄变化。 */
  height: calc(76px * (1 - var(--hdr-shrink, 0) * 0.4));
  width: 100%;
  max-width: none;
  padding-left: var(--pad);
  padding-right: var(--pad);

  /* 常驻底衬：首屏的点阵会从这一条打穿、压着品牌名与导航文字，没有底衬读不清。
     浓度由 --hdr-p 连续插值（静止 0.30/6px → 滚开 0.72/16px），只此一层。

     之前那版是两层状态切换：滚开时整条 .hdr 磨砂、这条退回全透明；回到顶部时反过来
     （整条退掉、这条重新磨砂）。于是下滑再上滑到顶，这一条就是「先变透明、再重新
     磨砂」——看着像逻辑出错。现在这条永远有底衬，只在浓度上连续变化，不存在状态切换。
     注：别把原因写成 backdrop-filter 的 none 不可插值 —— 实测 Chrome 能把
     blur(18px) ↔ none 平滑插值，跳变另有其因。 */
  background: rgba(6, 7, 13, calc(0.3 + var(--hdr-p, 0) * 0.42));
  /* 折射必须排在前面：先做边缘折射、再模糊，"玻璃厚度"才出得来。
     --hdr-lens 由脚本在贴图挂好之后才赋值（回退值是 blur(0px) 这种无害的空滤镜），
     否则首帧会拿一个空 feImage 去位移背景，会闪一下。 */
  -webkit-backdrop-filter: var(--hdr-lens, blur(0px)) blur(calc(6px + var(--hdr-p, 0) * 10px))
    saturate(calc(120% + var(--hdr-p, 0) * 30%));
  backdrop-filter: var(--hdr-lens, blur(0px)) blur(calc(6px + var(--hdr-p, 0) * 10px))
    saturate(calc(120% + var(--hdr-p, 0) * 30%));
  /* 镜面高光：顶边一道亮边；底边一道弱边（透镜在下边缘，那里要留一点回光） */
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12), inset 0 -1px 0 rgba(255, 255, 255, 0.06);
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

/* 会话未确定时的占位：与头像同尺寸、完全不可见。
   作用只是"先占住位置"，免得页头在恢复会话的那一瞬间抖一下。 */
.hdr__pending {
  display: block;
  width: 34px;
  height: 34px;
}

/* 登录/注册：纯文字入口，排版与 .nav a 同一套（无边框、无底色、无胶囊）。
   别再给它加回 .btn —— 那个按钮固定 42px 高，而条收薄到 45.6px 时上下只剩 3.6px 余量。 */
.hdr__login {
  font-size: 14px;
  letter-spacing: 0.08em;
  color: var(--text-dim);
  transition: color 0.35s var(--ease);
}

.hdr__login:hover {
  color: var(--text);
}

/* ---------------------------- 已登录：头像 + 悬浮卡 ---------------------------- */
.me {
  position: relative;
  display: flex;
  align-items: center;
}

.me__btn {
  position: relative;
  display: block;
  border-radius: 50%;
  transition: transform 0.45s var(--ease);
}

/* 未读公告角标：挑最刺眼的红，外圈留一道与页头同色的边框当"挖空" */
.me__badge {
  position: absolute;
  right: -5px;
  bottom: -5px;
  display: grid;
  place-items: center;
  min-width: 17px;
  height: 17px;
  padding: 0 4px;
  border: 2px solid var(--bg);
  border-radius: 99px;
  background: #ff5c5c;
  color: #fff;
  font-size: 10.5px;
  font-weight: 600;
  line-height: 1;
  letter-spacing: 0;
}

.me__img {
  display: block;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  border: 1px solid rgba(255, 255, 255, 0.18);
  transition: border-color 0.4s var(--ease), box-shadow 0.4s var(--ease);
}

/* 头像上移半格 + 起一圈青辉：先给个"下面要弹出东西"的暗示，再让卡片滑下来 */
.me:hover .me__btn,
.me:focus-within .me__btn {
  transform: translateY(-3px);
}

.me:hover .me__img,
.me:focus-within .me__img {
  border-color: rgba(110, 231, 255, 0.55);
  box-shadow:
    0 0 0 4px rgba(110, 231, 255, 0.1),
    0 10px 24px -12px rgba(110, 231, 255, 0.9);
}

.me__pop {
  position: absolute;
  top: 100%;
  right: 0;
  /* 这一段 padding 是"桥"：鼠标从头像挪到卡上时仍算在卡片范围内 */
  padding-top: 12px;
  width: 272px;
  opacity: 0;
  visibility: hidden;
  transform: translateY(-10px);
  transition:
    opacity 0.28s var(--ease),
    transform 0.45s var(--ease),
    visibility 0s linear 0.45s;
}

.me:hover .me__pop,
.me:focus-within .me__pop {
  opacity: 1;
  visibility: visible;
  transform: none;
  transition-delay: 0.06s;
}

.me__card {
  position: relative;
  padding: 14px;
  border: 1px solid var(--line-strong);
  border-radius: 16px;
  background: linear-gradient(180deg, rgba(16, 20, 34, 0.96), rgba(8, 10, 18, 0.98));
  -webkit-backdrop-filter: blur(18px) saturate(140%);
  backdrop-filter: blur(18px) saturate(140%);
  box-shadow:
    0 30px 70px -30px rgba(0, 0, 0, 0.95),
    0 0 0 1px rgba(110, 231, 255, 0.06),
    inset 0 1px 0 rgba(255, 255, 255, 0.06);
  overflow: hidden;
}

.me__top {
  position: relative;
  display: flex;
  align-items: center;
  gap: 11px;
  padding-bottom: 13px;
  border-bottom: 1px solid var(--line);
}

.me__avatar {
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  border: 1px solid rgba(255, 255, 255, 0.16);
}

.me__who {
  min-width: 0;
  flex: 1;
}

.me__name {
  font-size: 14px;
  line-height: 1.4;
}

.me__mail {
  font-size: 12px;
  line-height: 1.5;
  color: var(--muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.me__tier {
  flex: none;
  padding: 3px 9px;
  border: 1px solid rgba(242, 209, 141, 0.38);
  border-radius: 99px;
  font-size: 11px;
  letter-spacing: 0.06em;
  color: var(--gold);
  background: rgba(242, 209, 141, 0.1);
}

.me__stats {
  position: relative;
  margin: 0;
  padding: 4px 0;
  list-style: none;
}

.me__stats li {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  padding: 6px 0;
}

.me__k {
  font-size: 12px;
  letter-spacing: 0.06em;
  color: var(--muted);
}

.me__v {
  font-size: 13px;
  color: var(--text);
}

.me__v--mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.06em;
}

.me__v--gold {
  font-weight: 500;
  color: var(--gold);
}

/* 未读两行：公告 / 通知，各带一个数字和一个「›」 */
.me__unread {
  position: relative;
  display: grid;
  gap: 2px;
  margin-top: 8px;
  padding-top: 10px;
  border-top: 1px solid var(--line);
}

.me__un {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 7px 10px;
  border-radius: 9px;
  font-size: 12.5px;
  color: var(--text-dim);
  transition: color 0.3s var(--ease), background 0.3s var(--ease);
}

.me__un:hover {
  color: var(--text);
  background: rgba(255, 255, 255, 0.05);
}

.me__un-k {
  flex: none;
  letter-spacing: 0.08em;
}

.me__un-v {
  flex: 1;
  text-align: right;
  font-size: 12px;
  color: var(--muted);
}

.me__un-v b {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 13px;
  font-weight: 500;
  color: var(--cyan);
}

.me__un-go {
  flex: none;
  font-size: 15px;
  line-height: 1;
  color: var(--cyan);
  opacity: 0.55;
  transition: transform 0.3s var(--ease), opacity 0.3s var(--ease);
}

.me__un:hover .me__un-go {
  opacity: 1;
  transform: translateX(2px);
}

.me__acts {
  position: relative;
  display: grid;
  gap: 2px;
  margin-top: 8px;
  padding-top: 10px;
  border-top: 1px solid var(--line);
}

.me__act {
  display: block;
  width: 100%;
  padding: 8px 10px;
  border: 0;
  border-radius: 9px;
  background: transparent;
  color: var(--text-dim);
  font: inherit;
  font-size: 13px;
  text-align: left;
  cursor: pointer;
  transition: color 0.3s var(--ease), background 0.3s var(--ease);
}

.me__act:hover {
  color: var(--text);
  background: rgba(255, 255, 255, 0.05);
}

.me__act--out:hover {
  color: #ff9b9b;
  background: rgba(255, 120, 120, 0.08);
}

@media (max-width: 860px) {
  .nav {
    display: none;
  }
  .hdr__login,
  .me {
    margin-left: auto;
  }
}

@media (prefers-reduced-motion: reduce) {
  .me__btn,
  .me__img,
  .me__pop,
  .me__act {
    transition: none;
  }

  .me:hover .me__btn,
  .me:focus-within .me__btn {
    transform: none;
  }
}
</style>
