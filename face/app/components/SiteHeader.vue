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

      <!-- 登录/注册入口：纯文字，无按钮样式。href 暂时是占位锚点：项目里还没有登录页，
           等有实际地址（站内页或外部系统）时把它换掉即可。 -->
      <a class="hdr__login" href="#login">登录/注册</a>
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

@media (max-width: 860px) {
  .nav {
    display: none;
  }
  .hdr__login {
    margin-left: auto;
  }
}
</style>
