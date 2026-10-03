<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useMessage } from 'naive-ui'
import ClockBackdrop from '@/components/login/ClockBackdrop.vue'
import { transitionTo } from '@/composables/usePageVeil'
import { ApiError, apiRequest, saveToken } from '@/api/client'
import { setMyPermissions } from '@/api/permissions'

/**
 * 管理端登录 / 注册页。
 *
 * 层次（从下往上）：
 *   ClockBackdrop  巨型点阵数字时钟 + 星场（Canvas）
 *   bg-aurora      青/紫辉光雾
 *   bg-grid        缓慢平移的科技网格（径向遮罩收边）
 *   bg-scan        行扫描纹理 + 一道自上而下的扫光
 *   bg-vignette    暗角
 *   stage / hud    双栏卡片 + 四角 HUD
 *
 * 卡片是"两块拼图"：一半是表单、一半是视觉图。
 * 登录态表单在左、图在右；注册态整块对调（两栏各平移 100%），
 * 所以切换不是"换内容"而是"两块牌子互换位置"，动作更连贯。
 *
 * 刻意不做鼠标跟随（倾斜 / 光斑 / 视差）—— 幅度小了没感觉、大了干扰输入，
 * 卡片边缘那圈跑马灯已经足够给"活着"的感觉。
 */

type Mode = 'login' | 'register'

const router = useRouter()
const message = useMessage()

const mode = ref<Mode>('login')
const remember = ref(true)
const loading = ref(false)
const granted = ref(false)
const shaking = ref(false)
const ready = ref(false)
const now = ref(new Date())

const loginForm = reactive({ username: '', password: '' })
const registerForm = reactive({ username: '', password: '', confirm: '' })

const cardRef = ref<HTMLElement | null>(null)
const isRegister = computed(() => mode.value === 'register')

const pad = (n: number) => (n < 10 ? `0${n}` : String(n))

const dateText = computed(() => {
  const d = now.value
  return `${d.getFullYear()}.${pad(d.getMonth() + 1)}.${pad(d.getDate())}`
})

const timeText = computed(
  () => `${pad(now.value.getHours())}:${pad(now.value.getMinutes())}:${pad(now.value.getSeconds())}`,
)

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms))

let timer = 0

function shake() {
  shaking.value = false
  // 读一次 offsetWidth 强制重排，否则同名动画不会重播
  void cardRef.value?.offsetWidth
  shaking.value = true
}

function switchMode(next: Mode) {
  // 提交中不让切，避免把请求结果落到已经滑走的表单上
  if (loading.value || mode.value === next) return
  mode.value = next
}

async function onLogin() {
  if (loading.value) return

  if (!loginForm.username.trim() || !loginForm.password) {
    shake()
    message.warning('请输入账号与密码')
    return
  }

  loading.value = true
  try {
    // 真接口：core 的 Sanctum 登录
    const data = await apiRequest<{ token: string, permissions: string[] }>('/api/v1/admin/login', {
      method: 'POST',
      body: {
        username: loginForm.username.trim(),
        password: loginForm.password,
        device_name: 'web',
      },
    })

    // 令牌落本地（24 小时），后续请求由 apiRequest 自动带上 Authorization
    saveToken(data.token)

    // 登录响应里已经把"我自己的权限"带回来了，直接存下 —— 省一次请求，
    // 也保证进后台第一屏就知道哪些按钮该画
    setMyPermissions(data.permissions ?? [])

    granted.value = true
    message.success('身份校验通过，正在接入控制台')
    await sleep(260) // 先让全屏青光炸一下，闸门再合拢接管
    // 换页交给闸门过渡（合拢 → 换页 → 揭开），中间那一下路由跳变被完全盖住
    await transitionTo(() => router.push({ name: 'admin-overview' }))
  } catch (error) {
    shake()
    // 后端给的 message 已经是人话（账号或密码不正确 / 账号被禁用 / 请求过于频繁）
    message.error(error instanceof ApiError ? error.message : '授权服务不可达，请稍后再试')
  } finally {
    loading.value = false
  }
}

async function onRegister() {
  if (loading.value) return

  if (!registerForm.username.trim() || !registerForm.password) {
    shake()
    message.warning('请填写账号与密码')
    return
  }
  if (registerForm.password !== registerForm.confirm) {
    shake()
    message.warning('两次输入的密码不一致')
    return
  }

  loading.value = true
  try {
    /**
     * TODO 后端对接点：POST /api/v1/admin/register。
     * 管理端注册通常不直接放行 —— 建议后端落一条"待审核申请"，
     * 由超管在控制台里批准后才真正建号，前端这里只负责提交与提示。
     */
    await sleep(1100)
    message.success('申请已提交，等待管理员审核开通')
    registerForm.username = ''
    registerForm.password = ''
    registerForm.confirm = ''
    mode.value = 'login'
  } catch {
    shake()
    message.error('授权服务不可达，请稍后再试')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  // 双 rAF：确保首帧已按初始样式渲染，下一帧再切类，入场过渡才会真正播放
  requestAnimationFrame(() => requestAnimationFrame(() => (ready.value = true)))
  timer = window.setInterval(() => (now.value = new Date()), 1000)
})

onBeforeUnmount(() => {
  clearInterval(timer)
})
</script>

<template>
  <div class="login" :class="{ 'is-ready': ready, 'is-granted': granted, 'is-shaking': shaking }">
    <!-- 背景：巨型数字时钟 + 星场 -->
    <ClockBackdrop class="bg-canvas" />

    <!-- 背景：辉光 / 网格 / 扫描线 / 暗角，纯 CSS，见下方 style -->
    <div class="bg-aurora" aria-hidden="true"></div>
    <div class="bg-grid" aria-hidden="true"></div>
    <div class="bg-scan" aria-hidden="true"></div>
    <div class="bg-vignette" aria-hidden="true"></div>
    <div class="flash" aria-hidden="true"></div>

    <header class="hud hud-top">
      <div class="brand">
        <svg class="brand-mark" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M12 2.6 21.4 12 12 21.4 2.6 12Z" fill="none" stroke="currentColor" stroke-width="1.3" />
          <path d="M12 7.4 16.6 12 12 16.6 7.4 12Z" fill="currentColor" />
        </svg>
        <span class="brand-name">南门拈星</span>
        <span class="brand-sub">管理控制台</span>
      </div>

      <div class="status">
        <span class="status-dot"></span>
        <span>系统在线</span>
        <span class="status-sep"></span>
        <span class="dim">TLS 1.3 · 已加密</span>
      </div>
    </header>

    <main class="stage">
      <section ref="cardRef" class="card" :class="{ 'is-register': isRegister }">
        <!-- 表单栏：登录态在左，注册态整块滑到右 -->
        <div class="pane-form">
          <div class="form-block" :class="{ 'is-active': !isRegister }" :aria-hidden="isRegister">
            <div class="card-head">
              <div class="eyebrow"><span class="eyebrow-line"></span>SECURE ACCESS</div>
              <h1 class="title">接入控制台</h1>
              <p class="subtitle">请通过管理员身份验证，接入南门拈星交易系统。</p>
            </div>

            <form class="form" @submit.prevent="onLogin">
              <label class="field" for="login-username">
                <span class="field-label">账号</span>
                <n-input
                  v-model:value="loginForm.username"
                  size="large"
                  placeholder="管理员账号"
                  :input-props="{
                    id: 'login-username',
                    name: 'username',
                    autocomplete: 'username',
                    spellcheck: false,
                  }"
                >
                  <template #prefix>
                    <svg class="field-icon" viewBox="0 0 24 24" aria-hidden="true">
                      <circle cx="12" cy="8" r="3.6" />
                      <path d="M4.8 20.2a7.4 7.4 0 0 1 14.4 0" />
                    </svg>
                  </template>
                </n-input>
              </label>

              <label class="field" for="login-password">
                <span class="field-label">密码</span>
                <n-input
                  v-model:value="loginForm.password"
                  type="password"
                  show-password-on="click"
                  size="large"
                  placeholder="登录密码"
                  :input-props="{
                    id: 'login-password',
                    name: 'password',
                    autocomplete: 'current-password',
                  }"
                >
                  <template #prefix>
                    <svg class="field-icon" viewBox="0 0 24 24" aria-hidden="true">
                      <rect x="4.6" y="10.2" width="14.8" height="9.4" rx="2.4" />
                      <path d="M8.4 10.2V7.6a3.6 3.6 0 0 1 7.2 0v2.6" />
                    </svg>
                  </template>
                </n-input>
              </label>

              <div class="row">
                <n-checkbox v-model:checked="remember" size="small">保持登录状态</n-checkbox>
                <span class="hint">忘记密码请联系运维</span>
              </div>

              <div class="submit-wrap">
                <button class="submit" type="submit" :disabled="loading">
                  <span class="submit-text">{{ loading ? '正在校验身份' : '进入控制台' }}</span>
                  <span class="submit-sheen" aria-hidden="true"></span>
                </button>
              </div>
            </form>

            <p class="switch">
              还没有管理员账号？
              <button class="switch-btn" type="button" @click="switchMode('register')">
                申请开通
              </button>
            </p>
          </div>

          <div class="form-block" :class="{ 'is-active': isRegister }" :aria-hidden="!isRegister">
            <div class="card-head">
              <div class="eyebrow"><span class="eyebrow-line"></span>ACCOUNT REQUEST</div>
              <h1 class="title">申请开通</h1>
              <p class="subtitle">提交后由管理员审核开通，账号仅限团队内部使用。</p>
            </div>

            <form class="form" @submit.prevent="onRegister">
              <label class="field" for="register-username">
                <span class="field-label">账号</span>
                <n-input
                  v-model:value="registerForm.username"
                  size="large"
                  placeholder="设置登录账号"
                  :input-props="{
                    id: 'register-username',
                    name: 'new-username',
                    autocomplete: 'username',
                    spellcheck: false,
                  }"
                >
                  <template #prefix>
                    <svg class="field-icon" viewBox="0 0 24 24" aria-hidden="true">
                      <circle cx="12" cy="8" r="3.6" />
                      <path d="M4.8 20.2a7.4 7.4 0 0 1 14.4 0" />
                    </svg>
                  </template>
                </n-input>
              </label>

              <label class="field" for="register-password">
                <span class="field-label">密码</span>
                <n-input
                  v-model:value="registerForm.password"
                  type="password"
                  show-password-on="click"
                  size="large"
                  placeholder="至少 8 位，含字母与数字"
                  :input-props="{
                    id: 'register-password',
                    name: 'new-password',
                    autocomplete: 'new-password',
                  }"
                >
                  <template #prefix>
                    <svg class="field-icon" viewBox="0 0 24 24" aria-hidden="true">
                      <rect x="4.6" y="10.2" width="14.8" height="9.4" rx="2.4" />
                      <path d="M8.4 10.2V7.6a3.6 3.6 0 0 1 7.2 0v2.6" />
                    </svg>
                  </template>
                </n-input>
              </label>

              <label class="field" for="register-confirm">
                <span class="field-label">确认密码</span>
                <n-input
                  v-model:value="registerForm.confirm"
                  type="password"
                  show-password-on="click"
                  size="large"
                  placeholder="再次输入密码"
                  :input-props="{
                    id: 'register-confirm',
                    name: 'confirm-password',
                    autocomplete: 'new-password',
                  }"
                >
                  <template #prefix>
                    <svg class="field-icon" viewBox="0 0 24 24" aria-hidden="true">
                      <path d="M4.8 12.6 9.2 17 19.2 7" />
                    </svg>
                  </template>
                </n-input>
              </label>

              <div class="submit-wrap">
                <button class="submit" type="submit" :disabled="loading">
                  <span class="submit-text">{{ loading ? '正在提交申请' : '提交申请' }}</span>
                  <span class="submit-sheen" aria-hidden="true"></span>
                </button>
              </div>
            </form>

            <p class="switch">
              已有管理员账号？
              <button class="switch-btn" type="button" @click="switchMode('login')">
                返回登录
              </button>
            </p>
          </div>
        </div>

        <!-- 视觉栏：登录态在右，注册态整块滑到左 -->
        <div class="pane-visual">
          <img class="visual-img" src="/images/login-visual.webp" alt="" aria-hidden="true" />
          <div class="visual-veil" aria-hidden="true"></div>
          <div class="visual-grid" aria-hidden="true"></div>
          <div class="visual-copy">
            <p class="visual-eyebrow">NMNX TRADING SYSTEM</p>
            <p class="visual-title">主控调度 · 跟随执行 · 授权内核</p>
          </div>
        </div>
      </section>
    </main>

    <footer class="hud hud-bottom">
      <span class="dim">仅限授权人员访问</span>

      <span class="foot-right">
        <span class="dim">技术支持 · 南门拈星</span>
        <span class="foot-sep" aria-hidden="true"></span>
        <span class="dim mono">{{ dateText }} · {{ timeText }} · GMT+8</span>
      </span>
    </footer>
  </div>
</template>

<style scoped>
.login {
  /* 与官网 face/app/assets/css/main.css 同一套设计变量，改品牌色记得两边一起改 */
  --cyan: #6ee7ff;
  --violet: #a78bfa;
  --text: #e9ecf5;
  --dim: #a9b1c6;
  --muted: #7c849b;
  --ease: cubic-bezier(0.22, 1, 0.36, 1);
  --ease-soft: cubic-bezier(0.4, 0, 0.2, 1);

  position: relative;
  width: 100%;
  height: 100vh;
  height: 100dvh;
  overflow: hidden;
  isolation: isolate; /* 把混合模式关在本页内，别串到外层去 */
  background: radial-gradient(130% 110% at 50% -10%, #0d1430 0%, #070912 48%, #04050a 100%);
  color: var(--text);
}

/* ── 背景图层 ─────────────────────────────────────────────────────── */

.bg-canvas {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  z-index: 0;
}

.bg-aurora {
  position: absolute;
  /* 从 -20% 收到 -8%：filter: blur 的开销跟面积成正比，面积少一半就省一半；
     这块本来就要化开到看不见边界，收一点肉眼看不出差别 */
  inset: -8%;
  z-index: 1;
  background:
    radial-gradient(38% 32% at 18% 24%, rgba(110, 231, 255, 0.12), transparent 70%),
    radial-gradient(42% 36% at 84% 72%, rgba(167, 139, 250, 0.12), transparent 72%);
  filter: blur(18px);
  animation: aurora 26s ease-in-out infinite alternate;
}

/*
 * 网格层。
 *
 * 网格画在 ::before 上、用 transform 位移 —— **千万不要动 .bg-grid 自己的
 * background-position**。那会让整层每帧重绘，而这一层还挂着 mask-image（径向遮罩），
 * 重绘就意味着每帧重算一次遮罩。实测这是登录页掉帧的第二个大头。
 * 改成子元素位移后，遮罩留在父级上（静态），位移走合成层，开销趋近于零。
 */
.bg-grid {
  position: absolute;
  inset: 0;
  z-index: 1;
  overflow: hidden;
  /* 径向遮罩：中间留网格、四角化开，免得整屏格子抢戏 */
  -webkit-mask-image: radial-gradient(120% 92% at 50% 46%, #000 28%, transparent 78%);
  mask-image: radial-gradient(120% 92% at 50% 46%, #000 28%, transparent 78%);
}

.bg-grid::before {
  content: '';
  position: absolute;
  /* 四边各多出一格，位移满一个周期后不会露边 */
  inset: -64px;
  background-image:
    linear-gradient(rgba(110, 231, 255, 0.055) 1px, transparent 1px),
    linear-gradient(90deg, rgba(110, 231, 255, 0.055) 1px, transparent 1px);
  background-size: 64px 64px;
  animation: grid-drift 26s linear infinite;
  will-change: transform;
}

.bg-scan {
  position: absolute;
  inset: 0;
  z-index: 1;
  overflow: hidden;
  background: repeating-linear-gradient(
    to bottom,
    rgba(255, 255, 255, 0.035) 0 1px,
    transparent 1px 3px
  );
  opacity: 0.4;
  /* 去掉了 mix-blend-mode: overlay —— 它迫使这一整层每帧和下层做混合，
     低配机上代价明显；扫描线本身极淡，普通 alpha 叠加看不出区别 */
}

/* 一道自上而下扫过的光带 */
.bg-scan::after {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  height: 26vh;
  background: linear-gradient(to bottom, transparent, rgba(110, 231, 255, 0.08), transparent);
  animation: sweep 7.5s linear infinite;
}

.bg-vignette {
  position: absolute;
  inset: 0;
  z-index: 1;
  background: radial-gradient(120% 100% at 50% 50%, transparent 44%, rgba(2, 3, 8, 0.78) 100%);
}

/* 校验通过时的全屏青光 */
.flash {
  position: absolute;
  inset: 0;
  z-index: 5;
  pointer-events: none;
  opacity: 0;
  background: radial-gradient(58% 50% at 50% 50%, rgba(110, 231, 255, 0.5), transparent 70%);
}

.is-granted .flash {
  animation: flash 0.85s var(--ease) forwards;
}

/* ── HUD ──────────────────────────────────────────────────────────── */

.hud {
  position: absolute;
  left: 0;
  right: 0;
  z-index: 3;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: clamp(16px, 2.4vw, 30px) clamp(18px, 3vw, 42px);
  font-size: 11px;
  font-weight: 500;
  letter-spacing: 0.2em;
  text-transform: uppercase;

  opacity: 0;
  transform: translateY(-10px);
  transition:
    opacity 0.9s var(--ease) 0.5s,
    transform 0.9s var(--ease) 0.5s;
}

.hud-top {
  top: 0;
}

.hud-bottom {
  bottom: 0;
  transform: translateY(10px);
  transition-delay: 0.62s;
}

.is-ready .hud {
  opacity: 1;
  transform: none;
}

.dim {
  color: rgba(124, 132, 155, 0.85);
}

.mono {
  font-family: ui-monospace, 'SFMono-Regular', 'JetBrains Mono', Consolas, monospace;
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.12em;
}

.brand {
  display: flex;
  align-items: center;
  gap: 10px;
}

.brand-mark {
  width: 16px;
  height: 16px;
  color: var(--cyan);
  filter: drop-shadow(0 0 8px rgba(110, 231, 255, 0.85));
  animation: mark-pulse 3.6s ease-in-out infinite;
}

.brand-name {
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  /* 中文不能沿用拉丁那套 0.34em 字距，会散成一片，0.2em 才是汉字的舒适区 */
  letter-spacing: 0.2em;
}

.brand-sub {
  color: var(--muted);
  letter-spacing: 0.24em;
}

.brand-sub::before {
  content: '/';
  margin-right: 10px;
  color: rgba(110, 231, 255, 0.5);
}

.status {
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--dim);
}

.status-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--cyan);
  box-shadow: 0 0 0 0 rgba(110, 231, 255, 0.55);
  animation: beacon 2.4s ease-out infinite;
}

.status-sep {
  width: 1px;
  height: 12px;
  background: rgba(255, 255, 255, 0.14);
}

.foot-right {
  display: flex;
  align-items: center;
  gap: 12px;
}

.foot-sep {
  width: 1px;
  height: 11px;
  background: rgba(255, 255, 255, 0.14);
}

/* ── 卡片 ─────────────────────────────────────────────────────────── */

.stage {
  position: relative;
  z-index: 2;
  display: flex;
  height: 100%;
  overflow-y: auto;
  padding: clamp(76px, 11vh, 108px) 20px clamp(62px, 8vh, 88px);
}

.card {
  position: relative;
  /* margin auto + flex：内容比视口高时仍能滚到顶部（place-items center 会裁掉上边） */
  margin: auto;
  width: min(920px, 94vw);
  min-height: 540px;
  border: 1px solid rgba(255, 255, 255, 0.08);
  border-radius: 24px;
  overflow: hidden;
  /* 刻意做得比较透：背后的巨型时钟要能透出来。
     模糊只给 6px —— 再大就把点阵糊成一团光晕，看不清"现在几点"了。 */
  background: linear-gradient(180deg, rgba(14, 18, 34, 0.52), rgba(7, 9, 18, 0.6));
  backdrop-filter: blur(6px) saturate(130%);
  -webkit-backdrop-filter: blur(6px) saturate(130%);
  box-shadow:
    0 40px 90px -34px rgba(0, 0, 0, 0.92),
    0 0 0 1px rgba(110, 231, 255, 0.05),
    inset 0 1px 0 rgba(255, 255, 255, 0.06);

  /* 入场：模糊 + 上浮 + 微缩放，比单纯 fade 高级 */
  opacity: 0;
  transform: translateY(26px) scale(0.985);
  filter: blur(10px);
  transition:
    opacity 0.95s var(--ease) 0.1s,
    transform 0.95s var(--ease) 0.1s,
    filter 0.95s var(--ease) 0.1s;
}

.is-ready .card {
  opacity: 1;
  transform: none;
  filter: none;
}

/* 环形流光描边（跑马灯）：conic-gradient + mask 抠一圈 1px，
   靠 @property 让角度可插值，否则是逐帧跳变 */
.card::before {
  content: '';
  position: absolute;
  inset: 0;
  z-index: 3;
  border-radius: inherit;
  padding: 1px;
  pointer-events: none;
  background: conic-gradient(
    from var(--nmnx-halo),
    transparent 0turn,
    rgba(110, 231, 255, 0.85) 0.06turn,
    rgba(167, 139, 250, 0.5) 0.14turn,
    transparent 0.22turn,
    transparent 0.48turn,
    rgba(167, 139, 250, 0.55) 0.57turn,
    rgba(110, 231, 255, 0.75) 0.66turn,
    transparent 0.75turn
  );
  -webkit-mask:
    linear-gradient(#000 0 0) content-box,
    linear-gradient(#000 0 0);
  -webkit-mask-composite: xor;
  mask:
    linear-gradient(#000 0 0) content-box,
    linear-gradient(#000 0 0);
  mask-composite: exclude;
  animation: halo 6s linear infinite;
}

/* ── 表单栏 ───────────────────────────────────────────────────────── */

/*
 * 用 grid 叠放两个表单块（grid-area 相同）而不是绝对定位：
 * 卡片高度自动取两者中较高的那个，切换时高度不会跳。
 */
.pane-form {
  position: relative;
  z-index: 2;
  display: grid;
  width: 50%;
  padding: clamp(34px, 3.4vw, 46px) clamp(32px, 3.6vw, 50px);
  transition: transform 0.8s var(--ease);
  will-change: transform;
}

/*
 * 表单之间的切换也只走"左右"，不做上下位移、更不做缩放：
 *   默认状态一律停在 +44px（在右边等着被换上来），激活时归位；
 *   登录块在注册态则由下面那条规则改成 -44px（往左滑出）。
 * 于是切换就是"一进一出、擦身而过"的交叉滑动 —— 比原地淡入淡出顺得多。
 */
.form-block {
  grid-area: 1 / 1;
  opacity: 0;
  visibility: hidden;
  transform: translateX(44px);
  transition:
    opacity 0.5s var(--ease),
    transform 0.8s var(--ease),
    visibility 0s linear 0.8s;
}

.form-block.is-active {
  opacity: 1;
  visibility: visible;
  transform: none;
  transition-delay: 0.14s; /* 等两栏滑动起势之后再淡入，衔接更顺 */
}

/* 优先级高于 .form-block.is-active（3 个类 + 伪类），所以能覆盖它的 transform:none */
.card.is-register .form-block:first-child {
  transform: translateX(-44px);
}

.card-head {
  position: relative;
  z-index: 1;
}

.eyebrow {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 10.5px;
  font-weight: 500;
  letter-spacing: 0.3em;
  color: rgba(110, 231, 255, 0.9);
}

.eyebrow-line {
  width: 26px;
  height: 1px;
  background: linear-gradient(90deg, transparent, var(--cyan));
  box-shadow: 0 0 8px var(--cyan);
}

.title {
  margin: 13px 0 8px;
  font-size: 28px;
  font-weight: 700;
  letter-spacing: -0.01em;
  background: linear-gradient(120deg, #ffffff, #b9cffb);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
}

.subtitle {
  margin: 0;
  font-size: 13px;
  line-height: 1.75;
  /* 用 --dim 而不是 --muted：卡片透出时钟亮点后，再低的对比度就压不住了 */
  color: var(--dim);
}

.switch {
  margin: 20px 0 0;
  font-size: 12.5px;
  color: var(--muted);
}

.switch-btn {
  position: relative;
  padding: 0 2px;
  border: 0;
  background: none;
  font: inherit;
  font-weight: 600;
  color: var(--cyan);
  cursor: pointer;
}

.switch-btn::after {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  bottom: -2px;
  height: 1px;
  background: currentColor;
  transform: scaleX(0);
  transform-origin: right;
  transition: transform 0.35s var(--ease);
}

.switch-btn:hover::after {
  transform: scaleX(1);
  transform-origin: left;
}

/* ── 表单 ─────────────────────────────────────────────────────────── */

.form {
  position: relative;
  z-index: 1;
  margin-top: 24px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.field {
  display: block;
}

.field-label {
  display: block;
  margin-bottom: 8px;
  font-size: 10.5px;
  font-weight: 500;
  letter-spacing: 0.24em;
  text-transform: uppercase;
  color: var(--muted);
}

/*
 * 输入框的视觉参数（高度/圆角/底色/边框/聚焦辉光）**不在这里配** ——
 * 它们统一在 App.vue 的 themeOverrides.Input 里。
 * 页面里只留结构微调，见下面两条。
 */

.field :deep(.n-input__prefix) {
  margin-right: 10px;
}

.field-icon {
  width: 17px;
  height: 17px;
  fill: none;
  stroke: rgba(110, 231, 255, 0.7);
  stroke-width: 1.7;
  stroke-linecap: round;
  stroke-linejoin: round;
  transition: stroke 0.3s var(--ease-soft);
}

.field :deep(.n-input--focus) .field-icon {
  stroke: var(--cyan);
  filter: drop-shadow(0 0 6px rgba(110, 231, 255, 0.9));
}

.row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  font-size: 12px;
}

/* 复选框的文案字号与颜色同样在 App.vue 的 themeOverrides.Checkbox 里统一管 */

.hint {
  color: rgba(124, 132, 155, 0.9);
  font-size: 12px;
}

/* ── 提交按钮 ─────────────────────────────────────────────────────── */

.submit {
  position: relative;
  width: 100%;
  height: 50px;
  border: 0;
  border-radius: 12px;
  cursor: pointer;
  overflow: hidden;
  font: inherit;
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.28em;
  text-indent: 0.28em; /* 抵消字距在末尾多出的一格，视觉上才真正居中 */
  color: #04121a;
  background: linear-gradient(110deg, var(--cyan) 0%, #c4b1ff 52%, var(--cyan) 100%);
  background-size: 220% 100%;
  box-shadow:
    0 14px 34px -14px rgba(110, 231, 255, 0.85),
    inset 0 0 0 1px rgba(255, 255, 255, 0.28);
  transition:
    background-position 0.7s var(--ease),
    box-shadow 0.35s var(--ease-soft),
    transform 0.18s var(--ease-soft);
}

.submit:hover:not(:disabled) {
  background-position: 100% 0;
  box-shadow:
    0 18px 44px -14px rgba(110, 231, 255, 1),
    inset 0 0 0 1px rgba(255, 255, 255, 0.4);
}

.submit:active:not(:disabled) {
  transform: translateY(1px) scale(0.995);
}

.submit:disabled {
  cursor: not-allowed;
}

.submit-text {
  position: relative;
  z-index: 1;
}

/* 周期性掠过的高光 */
.submit-sheen {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 42%;
  background: linear-gradient(100deg, transparent, rgba(255, 255, 255, 0.75), transparent);
  transform: translateX(-150%) skewX(-18deg);
  animation: sheen 3.6s var(--ease-soft) infinite;
}

/* 加载中：叠一层流动斜纹，替代转圈图标 */
.submit:disabled::after {
  content: '';
  position: absolute;
  inset: 0;
  background: repeating-linear-gradient(
    115deg,
    rgba(4, 18, 26, 0.16) 0 10px,
    transparent 10px 22px
  );
  animation: stripes 0.6s linear infinite;
}

/* ── 视觉栏（图片） ───────────────────────────────────────────────── */

.pane-visual {
  position: absolute;
  top: 0;
  bottom: 0;
  left: 50%;
  width: 50%;
  overflow: hidden;
  transition: transform 0.8s var(--ease);
  will-change: transform;
}

/* 两栏互换：表单滑到右半，视觉栏滑到左半 */
.card.is-register .pane-form {
  transform: translateX(100%);
}

.card.is-register .pane-visual {
  transform: translateX(-100%);
}

.visual-img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  /* 图是静止的：切换动画要保持"只有左右位移"这一件事，
     图片上再叠缓慢推近就会变成"移动中还在放大"，反而显得脏 */
  filter: saturate(120%) contrast(105%);
}

/* 压暗 + 青色氛围，把图拉进页面的调子里 */
.visual-veil {
  position: absolute;
  inset: 0;
  background:
    linear-gradient(180deg, rgba(6, 7, 13, 0.5) 0%, rgba(6, 7, 13, 0.16) 38%, rgba(6, 7, 13, 0.88) 100%),
    radial-gradient(80% 60% at 50% 40%, rgba(110, 231, 255, 0.14), transparent 72%);
}

.visual-grid {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgba(110, 231, 255, 0.06) 1px, transparent 1px),
    linear-gradient(90deg, rgba(110, 231, 255, 0.06) 1px, transparent 1px);
  background-size: 44px 44px;
  -webkit-mask-image: radial-gradient(110% 80% at 50% 40%, #000 20%, transparent 74%);
  mask-image: radial-gradient(110% 80% at 50% 40%, #000 20%, transparent 74%);
}

.visual-copy {
  position: absolute;
  left: 34px;
  right: 34px;
  bottom: 32px;
}

.visual-eyebrow {
  margin: 0 0 8px;
  font-size: 10.5px;
  font-weight: 600;
  letter-spacing: 0.3em;
  text-transform: uppercase;
  color: rgba(110, 231, 255, 0.9);
}

.visual-title {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  letter-spacing: 0.08em;
  color: #eef3ff;
}

/* ── 动画 ─────────────────────────────────────────────────────────── */

/* 注册成 <angle> 才能平滑插值，否则 conic-gradient 的角度是逐帧跳变的 */
@property --nmnx-halo {
  syntax: '<angle>';
  initial-value: 0deg;
  inherits: false;
}

@keyframes halo {
  to {
    --nmnx-halo: 360deg;
  }
}

@keyframes sheen {
  0% {
    transform: translateX(-150%) skewX(-18deg);
  }
  55%,
  100% {
    transform: translateX(340%) skewX(-18deg);
  }
}

@keyframes stripes {
  to {
    background-position: 34px 0;
  }
}

@keyframes sweep {
  from {
    transform: translateY(-30vh);
  }
  to {
    transform: translateY(130vh);
  }
}

@keyframes grid-drift {
  to {
    /* 位移而不是 background-position：前者走合成层，后者每帧重绘 */
    transform: translate3d(64px, 64px, 0);
  }
}

@keyframes aurora {
  from {
    transform: translate3d(-3%, -2%, 0) scale(1);
  }
  to {
    transform: translate3d(3%, 2%, 0) scale(1.08);
  }
}

@keyframes beacon {
  0% {
    box-shadow: 0 0 0 0 rgba(110, 231, 255, 0.55);
  }
  70%,
  100% {
    box-shadow: 0 0 0 9px rgba(110, 231, 255, 0);
  }
}

@keyframes mark-pulse {
  50% {
    opacity: 0.5;
  }
}

@keyframes flash {
  0% {
    opacity: 0;
    transform: scale(0.9);
  }
  35% {
    opacity: 0.85;
  }
  100% {
    opacity: 0;
    transform: scale(1.15);
  }
}

@keyframes shake {
  10%,
  90% {
    transform: translateX(-2px);
  }
  20%,
  80% {
    transform: translateX(4px);
  }
  30%,
  50%,
  70% {
    transform: translateX(-7px);
  }
  40%,
  60% {
    transform: translateX(7px);
  }
}

/*
 * 抖动：类挂在父级、动画挂在卡片上。收尾不走 animationend ——
 * 卡片内部还有别的动画（按钮高光等）会冒泡上来，容易误触发。
 * 改为在 shake() 里先卸类 + 读 offsetWidth 强制重排，同名动画即可重播。
 */
.is-shaking .card {
  animation: shake 0.45s cubic-bezier(0.36, 0.07, 0.19, 0.97);
}

/* backdrop-filter 不支持时的兜底：卡片改实底，可读性优先 */
@supports not ((backdrop-filter: blur(4px)) or (-webkit-backdrop-filter: blur(4px))) {
  .card {
    background: linear-gradient(180deg, rgba(13, 16, 32, 0.97), rgba(7, 9, 18, 0.98));
  }
}

/* 系统设置里关了动效：停掉所有装饰性动画，时钟本身仍在走 */
@media (prefers-reduced-motion: reduce) {
  .bg-aurora,
  .bg-grid::before,
  .bg-scan::after,
  .card::before,
  .submit-sheen,
  .brand-mark,
  .status-dot {
    animation: none;
  }

  .card,
  .hud,
  .form-block,
  .pane-form,
  .pane-visual {
    transition-duration: 0.01ms;
    transition-delay: 0ms;
  }

  .is-shaking .card {
    animation: none;
  }
}

/* 窄屏：退回单栏，只留当前表单，图片栏不参与（放上去也只剩一条缝） */
@media (max-width: 900px) {
  .card {
    width: min(468px, 94vw);
    min-height: 0;
  }

  .pane-form {
    width: 100%;
    padding: 30px clamp(22px, 6vw, 34px);
  }

  .card.is-register .pane-form {
    transform: none;
  }

  .pane-visual {
    display: none;
  }
}

@media (max-width: 520px) {
  .card {
    border-radius: 18px;
  }

  .brand-sub,
  .status-sep,
  .status .dim {
    display: none;
  }

  .hud {
    letter-spacing: 0.14em;
  }

  .hud-bottom > .dim,
  .foot-right .dim:first-child {
    display: none;
  }

  .title {
    font-size: 25px;
  }
}
</style>
