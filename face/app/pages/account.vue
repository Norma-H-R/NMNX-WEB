<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'

/**
 * 用户中心（/account）—— 页头「登录/注册」的落点。
 *
 * 两态合一，同一路由内切换：
 *   未登录 —— 左「权益说明」+ 右玻璃卡（登录 / 注册 × 手机 / 邮箱 / 第三方）
 *   已登录 —— 账户面板：授权 / 设备 / 下载 / 订单 四块
 *
 * 未登录区的动效与 admin 的 LoginView 是同一套（那里是登录/注册两块牌子左右对调）：
 *   · 登录 / 注册 —— 两块表单在同一个网格单元里叠放，默认停在 +44px，
 *                    激活的归位、另一个退到 -44px，切换就是"擦身而过"的交叉滑动，
 *                    而不是原地淡入淡出；用叠放是为了让卡片高度取两者较高者，不跳。
 *   · 手机 / 邮箱 / 第三方 —— 只渲染当前那份字段，靠 :key 触发重建 + 一次入场动画。
 *   · 玻璃卡外圈一圈环形流光（conic-gradient + mask 抠 1px），靠 @property 让角度可插值。
 *   · 提交时叠一层流动斜纹；校验通过先炸一下全屏青光，再切到面板态。
 *
 * ⚠️ 接后端的进度（改之前先看这里）：
 *   · **登录已接**：手机号 / 邮箱 + 密码 → POST /api/v1/member/login。
 *     令牌与登录态由 composables/useAccount 管（本地持久化 + 4 小时超时）。
 *   · **注册还没接**：需要后端支持"手机号注册"（`users.email` 得可空），见 07-member.md 待办。
 *   · **验证码 / 第三方登录还没接**：属需求 C-6 的"预留接口"，UI 保留，点了会提示"开发中"。
 *   · 账户面板里的**授权 / 设备 / 订单 / 战报**仍是假数据：后端 License / Client 模块还没做。
 */

// ---------------------------------------------------------------------------
// 状态
//
// 登录态、用户资料、授权、倒计时都是**全站共享**的（见 composables/useAccount）——
// 页头右上角那个入口要跟着一起变成头像，状态就不能只待在这一页里。
// 下面几个是这一页自己的：主切换、登录方式、提交中、青光。
// ---------------------------------------------------------------------------
const {
  logged,
  sessionReady,
  user,
  licenses,
  countdown,
  avatarUrl,
  now,
  startClock,
  restoreSession,
  login,
  // 本页自己也有一个 logout()（负责复位这一页的状态），共享的那个改名以免撞名
  logout: logoutAccount,
} = useAccount()

const tab = ref('login') // 'login' | 'register'（主切换）
const way = ref('phone') // 'phone' | 'email' | 'third'（登录方式）
const loading = ref(false)
const granted = ref(false) // 校验通过 → 炸青光
const error = ref('') // 失败提示（直接显示后端给的 message）

/**
 * 表单值。模板里按字段名 `v-model="form[k]"` 绑定 ——
 * SCHEME 用到哪个字段，这里就得有那个键。
 */
const form = reactive({
  phone: '',
  email: '',
  code: '',
  password: '',
  newPassword: '',
})

// ---------------------------------------------------------------------------
// 表单schema：字段表 + 每种「态 × 方式」用哪几个字段
// 全部数据驱动，加一种登录方式只需要动这两张表，模板不用改
// ---------------------------------------------------------------------------
const F = {
  phone: { label: '手机号', type: 'tel', ac: 'tel', placeholder: '+86 138 0000 0000' },
  code: {
    label: '验证码',
    type: 'text',
    ac: 'one-time-code',
    placeholder: '6 位数字',
    withCode: true,
  },
  email: { label: '邮箱', type: 'email', ac: 'email', placeholder: 'member@nmnx.io' },
  // 登录密码。测试账号：手机号 11111 / 密码 11111（见 core 的 MemberSeeder）
  password: { label: '密码', type: 'password', ac: 'current-password', placeholder: '登录密码' },
  newPassword: {
    label: '设置密码',
    type: 'password',
    ac: 'new-password',
    placeholder: '至少 8 位，含字母与数字',
  },
}

// 每种「态 × 方式」用哪几个字段。
//
// 手机和邮箱走同一套：登录两行（账号 + 验证码，验证码即凭证），
// 注册三行（账号 + 验证码 + 设置密码）。
// 两种方式的字段数完全一致，两块表单又叠在同一个网格单元里，
// 高度天然相同 —— 切方式、切登录/注册，卡片都不会忽高忽低。
// 激活码不在这里收，改到账户面板里绑（见注册态那行说明）。
const SCHEME = {
  // 登录走「账号 + 密码」（不走验证码）：后端一期只实现了 手机号/邮箱 + 密码；
  // 验证码与第三方登录属于需求 C-6 的"预留接口"（见 core/docs/modules/07-member.md）
  login: {
    phone: ['phone', 'password'],
    email: ['email', 'password'],
  },
  register: {
    phone: ['phone', 'code', 'newPassword'],
    email: ['email', 'code', 'newPassword'],
  },
}

const WAYS = [
  { key: 'phone', label: '手机号' },
  { key: 'email', label: '邮箱' },
  { key: 'third', label: '第三方登录' },
]

const SUBMIT = {
  login: { text: '登录', busy: '正在校验身份' },
  register: { text: '创建账户', busy: '正在创建账户' },
}

// ---------------------------------------------------------------------------
// 假数据（占位，落地时换成接口返回）
// ---------------------------------------------------------------------------
const perks = [
  {
    no: '01',
    title: '授权一目了然',
    desc: '激活码、有效期、剩余天数，登录看到的永远是最新状态，不用翻聊天记录。',
  },
  {
    no: '02',
    title: '机器自己管',
    desc: '绑定了几台、哪台在用，随时解绑换机，不用发消息等处理。',
  },
  {
    no: '03',
    title: '文件随取随用',
    desc: '主控 EA、跟随端与接入手册都挂在你名下，换了机器直接重新下载。',
  },
]

// 博客：自己最近发的，按操作时间倒序（落地时换成接口返回）
const blogs = [
  { title: '把跟随端的开仓确认压到一帧内', meta: '已发布 · 阅读 428 · 评论 12', time: '今天 14:20' },
  { title: '点阵延迟 3ms 是怎么测出来的', meta: '已发布 · 阅读 316 · 评论 7', time: '昨天 21:05' },
  { title: '第七版主控的信号分发重构', meta: '已发布 · 阅读 271 · 评论 4', time: '10-01 09:12' },
  { title: '回测与实盘的滑点差异记录', meta: '草稿 · 还没发布', time: '09-28 23:41', draft: true },
  { title: '离线激活码的密钥轮换方案', meta: '已发布 · 阅读 195 · 评论 3', time: '09-24 16:30' },
]

// 论坛：最近浏览过的帖子 + 自己发过 / 回过的，同样按时间倒序
const forum = [
  { title: '主控 EA 在 TMMT5 上的开仓延迟', meta: '浏览 · 回帖 23', time: '今天 15:02' },
  { title: '跟随端 2 台和 6 台的差距在哪里', meta: '我回复了 #42', time: '今天 11:38', mine: true },
  { title: '离线激活码换机器要走什么流程', meta: '我发的主题 · 回复 9', time: '昨天 19:24', mine: true },
  { title: '回测报告里最大回撤的口径', meta: '浏览 · 回帖 16', time: '昨天 10:07' },
  { title: 'MT5 版本升级后跟随端要重装吗', meta: '浏览 · 回帖 31', time: '09-30 22:15' },
]

// 积分商城：可兑换的东西。积分不够的会置灰，不给人点了没反应的错觉
const store = [
  { name: '席位扩容 +1', cost: 1200, note: '当前授权追加一台机器', tag: '授权' },
  { name: '跟随端升级服务', cost: 800, note: '升级到当前最新版本', tag: '服务' },
  { name: '一对一接入协助', cost: 600, note: '远程协助完成首次接入', tag: '支持' },
  { name: '回测报告定制', cost: 500, note: '按你的参数出一份报告', tag: '研究' },
  { name: '专属定制指标', cost: 5000, note: '按需求开发一个 MQL 指标', tag: '定制' },
]

// 手写千分位而不是 toLocaleString：后者的分隔符取决于运行环境的 locale，
// SSR 与浏览器不一致就会 hydration 报错
const pointsText = computed(() => String(user.points).replace(/\B(?=(\d{3})+(?!\d))/g, ','))

const pad2 = (n) => (n < 10 ? `0${n}` : String(n))

const clockText = computed(() => {
  const d = new Date(now.value)
  return `${pad2(d.getHours())}:${pad2(d.getMinutes())}:${pad2(d.getSeconds())}`
})

// ---------------------------------------------------------------------------
// 授权列表
//
// 后期不只一种版本（主控 / 跟随端 / 回测引擎…，各自还分单机版、多机版、试用、
// 年费），所以这一块按"一份授权一行"来排，而不是固定一张卡只塞一份。
// 加新版本只是往 licenses 里多一项，模板与样式都不用动。
//
// 状态、剩余天数、已用比例全部由 expireAt 现算，不另外存字段 ——
// 免得服务端给的值和前端算出来的对不上。
// ---------------------------------------------------------------------------
const DAY = 86400000

const openLic = ref(licenses[0]?.id ?? '')

function toggleLic(id) {
  openLic.value = openLic.value === id ? '' : id
}

// 详情弹窗：点哪份授权的「详情」，就把哪份传进去；置回 null 就是关闭
const detailLic = ref(null)

const isDead = (l) => l.expireAt <= now.value
const leftDays = (l) => Math.max(0, Math.ceil((l.expireAt - now.value) / DAY))

const licPct = (l) => {
  const left = Math.max(0, (l.expireAt - now.value) / DAY)
  return Math.min(100, Math.max(0, Math.round(((l.totalDays - left) / l.totalDays) * 100)))
}

/** 生效中 / 即将到期（30 天内）/ 已过期 */
function licState(l) {
  const left = (l.expireAt - now.value) / DAY
  if (left <= 0) return { key: 'is-dead', label: '已过期', badge: 'badge--off' }
  if (left <= 30) return { key: 'is-soon', label: '即将到期', badge: 'badge--wait' }
  return { key: 'is-live', label: '生效中', badge: 'badge--on' }
}

const aliveCount = computed(() => licenses.filter((l) => l.expireAt > now.value).length)

// 悬浮卡上的钟：进这一页时把它点起来（幂等，页头那边已经在跑的话不会被重复启动）
// 同时恢复会话 —— 页头也会调（幂等），但这一页不该依赖"页头恰好挂了"这件事：
// 会话没恢复完，这一页就不知道该画登录卡还是面板（见下面模板里的 sessionReady 分支）
onMounted(() => {
  startClock()
  void restoreSession()
})

// ---------------------------------------------------------------------------
// 获取验证码：本地倒计时，不真的发短信（视觉稿）
// ---------------------------------------------------------------------------
const codeLeft = ref(0)
let codeTimer = 0

function sendCode() {
  if (codeLeft.value > 0) return
  codeLeft.value = 60
  codeTimer = window.setInterval(() => {
    codeLeft.value -= 1
    if (codeLeft.value <= 0) clearInterval(codeTimer)
  }, 1000)
}

// ---------------------------------------------------------------------------
// 复制授权码
//
// clipboard API 只在安全上下文（https / localhost）可用，且可能被权限拒绝，
// 所以留一条 execCommand 兜底；两条都不成就什么都不做 —— 不假装复制成功。
// 与联系区那张卡是同一套写法，暂不抽公共件，等第三处出现再说。
// ---------------------------------------------------------------------------
// 记 id 而不是一个布尔：列表里可能挂着好几份授权，只让刚点的那一条翻成"已复制"
const copiedId = ref('')
let copyTimer = 0

async function copyCode(text, id) {
  let ok = false
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
      ok = true
    }
  } catch {
    // 落到下面的兜底
  }
  if (!ok) {
    try {
      const ta = document.createElement('textarea')
      ta.value = text
      ta.setAttribute('readonly', '')
      ta.style.position = 'fixed'
      ta.style.top = '-1000px'
      document.body.appendChild(ta)
      ta.select()
      ok = document.execCommand('copy')
      document.body.removeChild(ta)
    } catch {
      ok = false
    }
  }
  if (!ok) return
  copiedId.value = id
  clearTimeout(copyTimer)
  copyTimer = window.setTimeout(() => (copiedId.value = ''), 1600)
}

onBeforeUnmount(() => {
  clearTimeout(copyTimer)
  clearInterval(codeTimer)
})

// ---------------------------------------------------------------------------
// 交互：登录**已接后端**（POST /api/v1/member/login）
//
// 注册、验证码、第三方登录**还没接** —— 它们属于后续工作（注册要先让后端的
// email 可空，见 core/docs/modules/07-member.md 待办）。
// ---------------------------------------------------------------------------
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

async function submit() {
  if (loading.value) return

  // 还没接后端的两种方式：先给一句人话，别让用户填完了才发现没反应
  if (way.value === 'third') {
    error.value = '第三方登录开发中，请先用手机号或邮箱登录'
    return
  }

  if (tab.value === 'register') {
    error.value = '注册接口开发中，请先用测试账号登录（手机号 11111 / 密码 11111）'
    return
  }

  error.value = ''
  loading.value = true

  try {
    // 手机号方式传 phone、邮箱方式传 email；后端两种都支持
    await login({
      ...(way.value === 'email' ? { email: form.email } : { phone: form.phone }),
      password: form.password,
    })

    // 成功：先炸一下青光再切面板态 —— 顺序跟 admin 一致（青光先起来，闸门后合拢）
    granted.value = true
    await sleep(280)
    tab.value = 'login'
    way.value = 'phone'
  } catch (err) {
    // 后端的 message 已经是人话（如"账号或密码不正确"），直接显示
    error.value = err?.message ?? '登录失败，请稍后重试'
  } finally {
    loading.value = false
    granted.value = false
  }
}

/** 退出：先清共享登录态（含吊销服务端令牌），再复位这一页自己的状态 */
async function logout() {
  await logoutAccount()
  tab.value = 'login'
  way.value = 'phone'
  error.value = ''
  copiedId.value = ''
}

function pick(next) {
  if (next === tab.value) return
  tab.value = next
  copiedId.value = ''
}

function pickWay(next) {
  if (next === way.value) return
  way.value = next
}
</script>

<template>
  <section class="section acct">
    <span class="acct__aura" aria-hidden="true" />

    <div class="container">
      <!--
        会话确定之前**什么都不画**。

        为什么不能直接按 `logged` 画：它在服务端渲染时必然是 false（服务端读不到
        localStorage），于是已登录的人刷新时会先看到登录卡，而两态外面还套着 swap 过渡，
        登录卡会被"淡出"再换成面板 —— 看起来就像先跳登录页、再跳回个人页。

        为什么是"空"而不是占位框：占位框（"正在恢复登录状态…"）用户明确不要。
        空着的话，页面背景与辉光仍在，看着就是"内容还没到"，比一个框更自然。
        代价是这一瞬间没有内容 —— 想连这一瞬间都消掉，得让服务端也能知道登录态
        （会话挪到 cookie），见 core/docs/modules/07-member.md。

        会话一确定（restoreSession 的同步段），Transition 才被创建；
        面板初次挂载没有 appear，所以不会再有进场动画，直接就是对的页面。
      -->
      <Transition v-if="sessionReady" name="swap" mode="out-in">
        <!-- ================= 未登录：登录 / 注册 ================= -->
        <div v-if="!logged" key="auth" class="auth">
          <div class="auth__head" v-reveal="{ selector: '.rv', stagger: 0.1 }">
            <p class="eyebrow rv">用户中心</p>
            <h1 class="title rv">你的授权，<em>都在这里</em></h1>
            <p class="lead rv">
              登录后可以查看激活码与有效期、管理绑定的机器、下载主控 EA 与跟随端。
            </p>
          </div>

          <div class="auth__body">
            <ol class="perks rv" v-reveal="{ selector: '.perks__item', stagger: 0.09, y: 28 }">
              <li v-for="p in perks" :key="p.no" class="perks__item rv">
                <span class="perks__no">{{ p.no }}</span>
                <div class="perks__main">
                  <h3 class="perks__title">{{ p.title }}</h3>
                  <p class="perks__desc">{{ p.desc }}</p>
                </div>
              </li>
            </ol>

            <div
              class="glass rv"
              :class="{ 'is-granted': granted }"
              v-reveal="{ y: 40, duration: 1.1 }"
            >
              <span class="glass__flash" aria-hidden="true" />
              <span class="glass__dots" aria-hidden="true" />
              <span class="glass__glow" aria-hidden="true" />

              <!-- 主切换：登录 / 注册 -->
              <div class="tabs" role="tablist" aria-label="登录或注册">
                <button
                  type="button"
                  role="tab"
                  class="tabs__btn"
                  :class="{ 'is-active': tab === 'login' }"
                  :aria-selected="tab === 'login'"
                  @click="pick('login')"
                >
                  登录
                </button>
                <button
                  type="button"
                  role="tab"
                  class="tabs__btn"
                  :class="{ 'is-active': tab === 'register' }"
                  :aria-selected="tab === 'register'"
                  @click="pick('register')"
                >
                  注册
                </button>
              </div>

              <!-- 登录方式：三种共用一条，切态时不动 -->
              <div class="ways" role="tablist" aria-label="登录方式">
                <button
                  v-for="w in WAYS"
                  :key="w.key"
                  type="button"
                  role="tab"
                  class="ways__btn"
                  :class="{ 'is-on': way === w.key }"
                  :aria-selected="way === w.key"
                  @click="pickWay(w.key)"
                >
                  {{ w.label }}
                </button>
              </div>

              <!--
                两块表单叠在同一个网格单元里（grid-area 相同）：
                卡片高度自动取较高那块，登录态下方不会撑出空洞、切换也不跳高度。
              -->
              <div class="panes" :class="{ 'is-register': tab === 'register' }">
                <div
                  v-for="p in ['login', 'register']"
                  :key="p"
                  class="pane"
                  :class="{ 'is-active': tab === p }"
                  :aria-hidden="tab !== p"
                >
                  <form class="form" autocomplete="on" @submit.prevent="submit">
                    <!-- 第三方：扫码 / 授权按钮 -->
                    <div v-if="way === 'third'" class="formset" :key="p + '-third'">
                      <div class="third">
                        <div class="third__qr">
                          <span>扫码授权</span>
                        </div>

                        <div class="third__col">
                          <button type="button" class="third__btn">
                            <svg class="third__icon" viewBox="0 0 24 24" aria-hidden="true">
                              <ellipse cx="9.4" cy="9.2" rx="7" ry="6" />
                              <ellipse cx="15.6" cy="15.4" rx="5.6" ry="4.8" />
                              <circle class="third__dot" cx="7" cy="8" r=".9" />
                              <circle class="third__dot" cx="11.8" cy="8" r=".9" />
                              <circle class="third__dot" cx="14" cy="14.4" r=".8" />
                              <circle class="third__dot" cx="17.4" cy="14.4" r=".8" />
                            </svg>
                            <span class="third__label">微信</span>
                            <span class="third__hint">扫码或确认授权</span>
                          </button>

                          <button type="button" class="third__btn">
                            <svg class="third__icon" viewBox="0 0 24 24" aria-hidden="true">
                              <path
                                d="M21.2 3.8 2.9 10.4a.5.5 0 0 0 .03.95l4.05 1.3 1.4 4.2a.5.5 0 0 0 .87.15l2.15-2.4 4.1 3a.5.5 0 0 0 .78-.3l3.2-12.9a.5.5 0 0 0-.62-.6Z"
                              />
                              <path d="M7 12.65 18.4 5.1l-8.4 8.5" />
                            </svg>
                            <span class="third__label">Telegram</span>
                            <span class="third__hint">跳转授权后自动返回</span>
                          </button>
                        </div>
                      </div>

                      <p class="form__note">
                        第三方授权只用于建立账户，不读取聊天内容；授权后仍可用手机号或邮箱登录。
                      </p>
                    </div>

                    <!-- 手机号 / 邮箱：字段由 SCHEME 表决定 -->
                    <div v-else class="formset" :key="p + '-' + way">
                      <label v-for="k in SCHEME[p][way]" :key="k" class="fld">
                        <span class="fld__label">{{ F[k].label }}</span>

                        <span v-if="F[k].withCode" class="fld__group">
                          <input
                            v-model="form[k]"
                            class="fld__input"
                            :type="F[k].type"
                            :autocomplete="F[k].ac"
                            :placeholder="F[k].placeholder"
                          />
                          <button
                            type="button"
                            class="mini fld__code"
                            :disabled="codeLeft > 0"
                            @click="sendCode"
                          >
                            {{ codeLeft > 0 ? codeLeft + 's 后重发' : '获取验证码' }}
                          </button>
                        </span>

                        <input
                          v-else
                          v-model="form[k]"
                          class="fld__input"
                          :class="{ 'fld__input--code': F[k].mono }"
                          :type="F[k].type"
                          :autocomplete="F[k].ac"
                          :placeholder="F[k].placeholder"
                        />
                      </label>

                      <div v-if="p === 'login'" class="form__aux">
                        <span>账号与密码请勿外传</span>
                        <a class="form__link" href="#">忘记密码？</a>
                      </div>
                      <p v-else class="form__note">
                        注册即表示同意服务条款；激活码可以在用户中心里随时绑定。
                      </p>

                      <!-- 登录失败的提示：文案直接来自后端（如"账号或密码不正确"） -->
                      <p v-if="error" class="form__error">{{ error }}</p>

                      <button
                        type="submit"
                        class="btn btn--primary form__submit"
                        :disabled="loading"
                      >
                        <span>{{ loading ? SUBMIT[p].busy : SUBMIT[p].text }}</span>
                        <span class="form__sheen" aria-hidden="true" />
                      </button>
                    </div>
                  </form>
                </div>
              </div>

            </div>

            <p class="glass__note">
              当前是视觉稿，未接入后端；点上面的按钮走一遍 loading 就会进面板演示。
            </p>
          </div>
        </div>

        <!-- ================= 已登录：账户面板 ================= -->
        <div v-else key="panel" class="panel">
          <!-- ---------- 概览：头像 + 身份 + 三个实时数据 ---------- -->
          <div class="panel__head">
            <div class="panel__intro">
              <p class="eyebrow">用户中心</p>
              <h1 class="title">欢迎回来，<em>{{ user.name }}</em></h1>
              <p class="lead">授权与服务状态一览。换机器、扩席位、续期，都在这里办。</p>
            </div>

            <div class="who">
              <img class="who__avatar" :src="avatarUrl" alt="" width="42" height="42" />
              <div class="who__meta">
                <p class="who__name">
                  {{ user.name }}
                  <span class="who__tier">{{ user.tier }}</span>
                </p>
                <p class="who__mail">{{ user.email }}</p>
              </div>
              <button type="button" class="who__out" @click="logout">退出登录</button>
            </div>
          </div>

          <ul class="metrics">
            <li class="metrics__item">
              <span class="metrics__k">当前时间</span>
              <!-- 秒级数字在 SSR 与客户端必然对不齐，包 ClientOnly 免得 hydration 报错 -->
              <ClientOnly>
                <span class="metrics__v metrics__v--mono">{{ clockText }}</span>
                <template #fallback>
                  <span class="metrics__v metrics__v--mono">--:--:--</span>
                </template>
              </ClientOnly>
            </li>

            <li class="metrics__item">
              <span class="metrics__k">最近到期</span>
              <ClientOnly>
                <span class="metrics__v metrics__v--mono">
                  {{ countdown.days }} 天 {{ pad2(countdown.hours) }}:{{
                    pad2(countdown.minutes)
                  }}:{{ pad2(countdown.seconds) }}
                </span>
                <template #fallback>
                  <span class="metrics__v metrics__v--mono">-- 天 --:--:--</span>
                </template>
              </ClientOnly>
            </li>

            <li class="metrics__item">
              <a class="metrics__k metrics__k--link" href="#points">积分 · 去商城</a>
              <span class="metrics__v metrics__v--gold">{{ pointsText }}</span>
            </li>
          </ul>

          <div class="grid">
            <!-- ---------- 授权：一份一行，点开看授权码 / 有效期 / 席位 ---------- -->
            <article class="tile tile--wide">
              <header class="tile__head">
                <h2 class="tile__title">授权</h2>
                <span class="tile__count">{{ aliveCount }} / {{ licenses.length }} 生效</span>
              </header>

              <ul class="lics">
                <li
                  v-for="l in licenses"
                  :key="l.id"
                  class="lic-row"
                  :class="{ 'is-open': openLic === l.id, 'is-expired': isDead(l) }"
                >
                  <button
                    type="button"
                    class="lic-row__head"
                    :aria-expanded="openLic === l.id"
                    @click="toggleLic(l.id)"
                  >
                    <span class="lic-row__dot" :class="licState(l).key" aria-hidden="true" />

                    <span class="lic-row__main">
                      <span class="lic-row__product">{{ l.product }}</span>
                      <span class="lic-row__edition">{{ l.edition }}</span>
                    </span>

                    <span class="badge" :class="licState(l).badge">{{ licState(l).label }}</span>

                    <svg class="lic-row__caret" viewBox="0 0 24 24" aria-hidden="true">
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

                  <div class="lic-row__body">
                    <div class="lic-row__body-in">
                      <div class="lic-fields">
                        <div class="lic-field lic-field--wide">
                          <span class="lic-field__k">授权码</span>
                          <span class="lic-field__v">
                            <code class="lic-field__code">{{ l.code }}</code>
                            <button
                              type="button"
                              class="mini"
                              :class="{ 'is-done': copiedId === l.id }"
                              @click.stop="copyCode(l.code, l.id)"
                            >
                              {{ copiedId === l.id ? '已复制' : '复制' }}
                            </button>
                          </span>
                        </div>

                        <div class="lic-field">
                          <span class="lic-field__k">有效期</span>
                          <span class="lic-field__v lic-field__v--mono">
                            {{ l.from }} → {{ l.to }}
                          </span>
                        </div>

                        <div class="lic-field">
                          <span class="lic-field__k">剩余</span>
                          <ClientOnly>
                            <span class="lic-field__v lic-field__v--mono">
                              {{ leftDays(l) }} 天
                            </span>
                            <template #fallback>
                              <span class="lic-field__v lic-field__v--mono">-- 天</span>
                            </template>
                          </ClientOnly>
                        </div>

                        <div class="lic-field">
                          <span class="lic-field__k">绑定席位</span>
                          <span class="lic-field__v lic-field__v--mono">
                            {{ l.used }} / {{ l.seats }}
                          </span>
                        </div>
                      </div>

                      <div
                        class="bar"
                        role="img"
                        :aria-label="`${l.product} 有效期已用 ${licPct(l)}%`"
                      >
                        <i :style="{ width: licPct(l) + '%' }" />
                      </div>
                      <p class="lic__hint">自 {{ l.from }} 起，有效期已用 {{ licPct(l) }}%</p>

                      <!-- 设备 + EA 表现（融合进授权）：盈利 EA 才给战报和「详情」 -->
                      <div v-if="l.device" class="ea">
                        <div class="ea__device">
                          <span class="ea__dot" :class="{ 'is-on': l.device.active }" aria-hidden="true" />
                          <span class="ea__device-text">{{ l.device.os }}</span>
                          <code class="ea__device-id">{{ l.device.id }}</code>
                        </div>

                        <template v-if="l.mode === 'live' && l.performance">
                          <div class="ea__report">
                            <div class="ea__pnl">
                              <span class="ea__pnl-k">今日盈亏</span>
                              <span class="ea__pnl-v">{{ l.performance.pnl }}</span>
                            </div>

                            <div class="ea__stats">
                              <span class="ea__stat">
                                <span class="ea__stat-k">笔</span>
                                <em class="up">{{ l.performance.winTrades }}</em>
                                <span class="sep">/</span>
                                <em class="down">{{ l.performance.lossTrades }}</em>
                              </span>
                              <span class="ea__stat">
                                <span class="ea__stat-k">单</span>
                                <em class="up">{{ l.performance.winOrders }}</em>
                                <span class="sep">/</span>
                                <em class="down">{{ l.performance.lossOrders }}</em>
                              </span>
                            </div>

                            <button type="button" class="ea__detail" @click.stop="detailLic = l">
                              详情
                            </button>
                          </div>
                        </template>

                        <p v-else class="ea__demo">测试环境运行，不计入盈亏统计。</p>
                      </div>
                    </div>
                  </div>
                </li>
              </ul>

              <p class="tile__note">
                一个产品一份授权。以后新增版本或席位，都会作为独立的一份出现在这里。
              </p>
            </article>

            <!-- ---------- 博客：我最近发的 ---------- -->
            <article class="tile">
              <header class="tile__head">
                <h2 class="tile__title">博客</h2>
                <a class="tile__more" href="/blog">全部</a>
              </header>

              <ul class="feed">
                <li v-for="b in blogs" :key="b.title" class="feed__item">
                  <span class="feed__dot" :class="{ 'is-draft': b.draft }" aria-hidden="true" />
                  <div class="feed__main">
                    <p class="feed__title">{{ b.title }}</p>
                    <p class="feed__meta">{{ b.meta }}</p>
                  </div>
                  <span class="feed__time">{{ b.time }}</span>
                </li>
              </ul>

              <p class="tile__note">按最近一次编辑时间排，没发的草稿也在里面。</p>
            </article>

            <!-- ---------- 论坛：最近浏览过的 + 自己发过回过的 ---------- -->
            <article class="tile">
              <header class="tile__head">
                <h2 class="tile__title">论坛</h2>
                <a class="tile__more" href="/forum">全部</a>
              </header>

              <ul class="feed">
                <li v-for="f in forum" :key="f.title" class="feed__item">
                  <span class="feed__dot" :class="{ 'is-mine': f.mine }" aria-hidden="true" />
                  <div class="feed__main">
                    <p class="feed__title">{{ f.title }}</p>
                    <p class="feed__meta">{{ f.meta }}</p>
                  </div>
                  <span class="feed__time">{{ f.time }}</span>
                </li>
              </ul>

              <p class="tile__note">最近浏览过的帖子，以及自己发过或回过的。</p>
            </article>

            <!-- ---------- 积分商城 ---------- -->
            <article id="points" class="tile tile--wide">
              <header class="tile__head">
                <h2 class="tile__title">积分商城</h2>
                <span class="tile__count">当前 {{ pointsText }} 分</span>
              </header>

              <ul class="store">
                <li
                  v-for="s in store"
                  :key="s.name"
                  class="store__item"
                  :class="{ 'is-locked': user.points < s.cost }"
                >
                  <span class="store__tag">{{ s.tag }}</span>
                  <p class="store__name">{{ s.name }}</p>
                  <p class="store__note">{{ s.note }}</p>
                  <div class="store__foot">
                    <span class="store__cost">{{ s.cost }} 分</span>
                    <button type="button" class="mini" :disabled="user.points < s.cost">
                      {{ user.points < s.cost ? '积分不足' : '兑换' }}
                    </button>
                  </div>
                </li>
              </ul>

              <p class="tile__note">
                兑换后直接落到你的账户上，不用再另外沟通；积分主要来自发帖与回帖。
              </p>
            </article>
          </div>

          <!-- 订单：常驻在视口右侧的悬浮按钮，点开才是抽屉 -->
          <AccountOrderDrawer />

          <!-- EA 表现详情弹窗：授权卡里的「详情」触发 -->
          <AccountTradeModal :lic="detailLic" @close="detailLic = null" />
        </div>
      </Transition>
    </div>
  </section>
</template>

<style scoped>
.acct {
  /* 站内码与编号统一走等宽，与联系区那张卡一致 */
  --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;

  /* 页头是 fixed（静止 76px），.section 默认的上内边距不够让开，这里单独给足 */
  padding-top: clamp(104px, 15vh, 168px);
}

/* 区块底光：右上偏紫、左侧偏青，两块很淡的辉光把版面托起来 */
.acct__aura {
  position: absolute;
  inset: 0;
  pointer-events: none;
  background:
    radial-gradient(58% 40% at 82% 4%, rgba(167, 139, 250, 0.16), transparent 68%),
    radial-gradient(50% 36% at 8% 38%, rgba(110, 231, 255, 0.12), transparent 70%);
}

/* ---------------------------- 通用小件 ---------------------------- */

/* 幽灵小按钮：复制 / 解绑 / 下载 / 获取验证码共用 */
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
  transition: color 0.35s var(--ease), border-color 0.35s var(--ease),
    background 0.35s var(--ease);
}

.mini:hover:not(:disabled) {
  color: var(--text);
  border-color: var(--cyan);
}

.mini.is-done {
  border-color: var(--cyan);
  background: var(--cyan);
  color: #06070d;
}

.mini:disabled {
  color: var(--muted);
  border-color: var(--line);
  cursor: not-allowed;
}

.badge {
  display: inline-flex;
  align-items: center;
  padding: 4px 11px;
  border: 1px solid transparent;
  border-radius: 99px;
  font-size: 12px;
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

/* 已过期：不告警也不抢眼，压成灰的 */
.badge--off {
  color: var(--muted);
  border-color: var(--line-strong);
  background: rgba(255, 255, 255, 0.04);
}

/* ---------------------------- 未登录：头部 ---------------------------- */
.auth__head {
  max-width: 620px;
}

/* ---------------------------- 未登录：左说明 / 右表单卡 ---------------------------- */
.auth__body {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(340px, 430px);
  /* 列间距照旧给得很大，行间距单独收小 —— 卡片下面那行脚注落在第二行 */
  column-gap: clamp(28px, 4vw, 76px);
  row-gap: 12px;
  /* 左栏永远按自己的内容高度站住，不被右边牵连 ——
     两栏要齐，靠的是把右侧内容本身压下来，而不是去拉左侧 */
  align-items: start;
  margin-top: clamp(40px, 5vw, 72px);
}

.perks {
  margin: 0;
  padding: 0;
  list-style: none;
}

.perks__item {
  position: relative;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: clamp(16px, 2.4vw, 34px);
  padding: clamp(20px, 2.6vw, 30px) 0 clamp(20px, 2.6vw, 30px) clamp(12px, 1.6vw, 22px);
  border-top: 1px solid var(--line);
  overflow: hidden;
}

.perks__item:last-child {
  border-bottom: 1px solid var(--line);
}

/* 色条：悬停时从左往右横向划过整行（与联系区的流程同一套手法） */
.perks__item::before {
  content: '';
  position: absolute;
  inset: 0;
  background: var(--grad);
  transform: scaleX(0);
  transform-origin: left;
  transition: transform 0.62s var(--ease);
}

.perks__item:hover::before {
  transform: scaleX(1);
}

.perks__no,
.perks__main {
  position: relative;
}

.perks__no,
.perks__title,
.perks__desc {
  transition: color 0.45s var(--ease);
}

.perks__item:hover .perks__no,
.perks__item:hover .perks__title,
.perks__item:hover .perks__desc {
  color: #06070d;
}

.perks__no {
  font-family: var(--mono);
  font-size: 13px;
  letter-spacing: 0.16em;
  color: var(--muted);
}

.perks__title {
  font-size: clamp(18px, 1.8vw, 23px);
  font-weight: 500;
  letter-spacing: 0.01em;
}

.perks__desc {
  margin-top: 10px;
  max-width: 42ch;
  font-size: clamp(14px, 1.02vw, 15.5px);
  line-height: 1.9;
  color: var(--text-dim);
}

/* ---------------------------- 未登录：玻璃表单卡 ---------------------------- */
.glass {
  position: relative;
  padding: clamp(16px, 1.4vw, 20px);
  border: 1px solid var(--line-strong);
  border-radius: 24px;
  background: linear-gradient(160deg, rgba(20, 24, 38, 0.92), rgba(9, 11, 20, 0.97));
  box-shadow: 0 30px 80px -44px rgba(110, 231, 255, 0.5);
  overflow: hidden;
}

/* 环形流光描边（跑马灯）：conic-gradient + mask 抠出一圈 1px。
   角度写成 <angle> 才能被插值，否则 conic-gradient 是逐帧跳变的（同 admin）。 */
@property --acct-halo {
  syntax: '<angle>';
  initial-value: 0deg;
  inherits: false;
}

.glass::before {
  content: '';
  position: absolute;
  inset: 0;
  z-index: 3;
  padding: 1px;
  border-radius: inherit;
  pointer-events: none;
  background: conic-gradient(
    from var(--acct-halo),
    transparent 0turn,
    rgba(110, 231, 255, 0.8) 0.06turn,
    rgba(167, 139, 250, 0.45) 0.14turn,
    transparent 0.22turn,
    transparent 0.48turn,
    rgba(167, 139, 250, 0.5) 0.57turn,
    rgba(110, 231, 255, 0.7) 0.66turn,
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
  animation: acct-halo 6s linear infinite;
}

@keyframes acct-halo {
  to {
    --acct-halo: 360deg;
  }
}

.glass__dots {
  position: absolute;
  inset: 0;
  pointer-events: none;
  background-image: radial-gradient(rgba(255, 255, 255, 0.075) 1px, transparent 1px);
  background-size: 15px 15px;
  -webkit-mask-image: radial-gradient(130% 85% at 50% -10%, #000, transparent 72%);
  mask-image: radial-gradient(130% 85% at 50% -10%, #000, transparent 72%);
}

.glass__glow {
  position: absolute;
  top: -34%;
  right: -22%;
  width: 72%;
  height: 112%;
  pointer-events: none;
  background: radial-gradient(closest-side, rgba(110, 231, 255, 0.16), transparent);
}

/* 校验通过时炸一下青光（同 admin 的 flash） */
.glass__flash {
  position: absolute;
  inset: 0;
  z-index: 3;
  pointer-events: none;
  opacity: 0;
  background: radial-gradient(62% 52% at 50% 46%, rgba(110, 231, 255, 0.5), transparent 72%);
}

.glass.is-granted .glass__flash {
  animation: acct-flash 0.85s var(--ease) forwards;
}

@keyframes acct-flash {
  0% {
    opacity: 0;
  }
  38% {
    opacity: 1;
  }
  100% {
    opacity: 0;
  }
}

.glass__note {
  position: relative;
  /* 挪到卡片之外了：落在第二行的右列，不再参与卡片的高度核算 ——
     右侧靠这一刀正好与左边那三条收在同一条线上 */
  grid-column: 2;
  font-size: 12px;
  line-height: 1.7;
  color: var(--muted);
}

/* ---------------------------- 登录 / 注册主切换 ---------------------------- */
.tabs {
  position: relative;
  display: flex;
  gap: 4px;
  padding: 4px;
  border: 1px solid var(--line);
  border-radius: 99px;
  background: rgba(255, 255, 255, 0.03);
}

.tabs__btn {
  flex: 1;
  padding: 7px 6px;
  border: 0;
  border-radius: 99px;
  background: transparent;
  color: var(--muted);
  font: inherit;
  font-size: 13.5px;
  letter-spacing: 0.08em;
  cursor: pointer;
  transition: color 0.35s var(--ease), background 0.45s var(--ease);
}

.tabs__btn:hover {
  color: var(--text);
}

.tabs__btn.is-active {
  background: var(--grad);
  color: #06070d;
}

/* ---------------------------- 登录方式：手机 / 邮箱 / 第三方 ---------------------------- */
.ways {
  position: relative;
  display: flex;
  flex-wrap: wrap;
  gap: 22px;
  margin-top: 12px;
  border-bottom: 1px solid var(--line);
}

.ways__btn {
  position: relative;
  padding: 0 0 7px;
  border: 0;
  background: none;
  font: inherit;
  font-size: 13px;
  letter-spacing: 0.06em;
  color: var(--muted);
  cursor: pointer;
  transition: color 0.35s var(--ease);
}

.ways__btn::after {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  bottom: -1px;
  height: 1px;
  background: var(--grad);
  transform: scaleX(0);
  transform-origin: left;
  transition: transform 0.45s var(--ease);
}

.ways__btn:hover {
  color: var(--text);
}

.ways__btn.is-on {
  color: var(--text);
}

.ways__btn.is-on::after {
  transform: scaleX(1);
}

/* ---------------------------- 未登录：两块表单交叉滑动 ---------------------------- */
.panes {
  position: relative;
  display: grid;
  margin-top: 14px;
  margin-bottom: 12px;
}

/*
 * 切换只走"左右"：默认一律停在 +44px（在右边等着被换上来），激活时归位；
 * 登录块在注册态由下面那条规则改成 -44px（往左滑出）。
 * 于是切换是"一进一出、擦身而过"的交叉滑动 —— 比原地淡入淡出顺得多。
 */
.pane {
  grid-area: 1 / 1;
  opacity: 0;
  visibility: hidden;
  transform: translateX(44px);
  transition:
    opacity 0.5s var(--ease),
    transform 0.8s var(--ease),
    visibility 0s linear 0.8s;
}

.pane.is-active {
  opacity: 1;
  visibility: visible;
  transform: none;
  /* 等滑动起势之后再淡入，衔接更顺 */
  transition-delay: 0.14s;
}

/* 优先级高于 .pane.is-active（两个类 + 伪类 vs 两个类），能覆盖它的 transform: none */
.panes.is-register .pane:first-child {
  transform: translateX(-44px);
}

.form {
  position: relative;
  display: flex;
  flex-direction: column;
  height: 100%;
  margin-top: 4px;
}

/* 方式切换：只渲染当前那份，靠 :key 重建触发一次入场（同 admin 的表单块换位）。
   竖排 + flex: 1，好让提交按钮用 margin-top: auto 压在卡片底部 ——
   登录态比注册态少一个字段，多出来的那点空间落在按钮上方，卡片底下不会空一块。 */
.formset {
  display: flex;
  flex-direction: column;
  flex: 1;
  gap: 10px;
  animation: acct-field 0.5s var(--ease) both;
}

@keyframes acct-field {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
}

/* ---------------------------- 表单字段 ---------------------------- */
.fld {
  display: grid;
  gap: 5px;
}

.fld__label {
  font-size: 12px;
  letter-spacing: 0.18em;
  color: var(--muted);
}

.fld__input {
  width: 100%;
  height: 40px;
  padding: 0 15px;
  border: 1px solid var(--line-strong);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.03);
  color: var(--text);
  font: inherit;
  font-size: 14.5px;
  letter-spacing: 0.02em;
  transition: border-color 0.35s var(--ease), background 0.35s var(--ease),
    box-shadow 0.35s var(--ease);
}

.fld__input::placeholder {
  color: #5b6278;
}

.fld__input:focus {
  outline: none;
  border-color: rgba(110, 231, 255, 0.55);
  background: rgba(110, 231, 255, 0.05);
  box-shadow: 0 0 0 4px rgba(110, 231, 255, 0.08);
}

.fld__input--code {
  font-family: var(--mono);
  font-size: 13.5px;
  letter-spacing: 0.08em;
}

/* 验证码：输入框 + 取码按钮同一行 */
.fld__group {
  display: flex;
  gap: 10px;
}

.fld__group .fld__input {
  flex: 1;
  min-width: 0;
}

.fld__code {
  /* 固定宽度：倒计时数字位数变化时不抖 */
  width: 106px;
  /* 跟 .fld__input 同高，同一行里才对得齐 */
  height: 40px;
  padding: 0;
  border-radius: 12px;
  font-size: 12.5px;
}

.form__aux {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: -2px;
  font-size: 12.5px;
  color: var(--muted);
}

.form__link {
  transition: color 0.35s var(--ease);
}

.form__link:hover {
  color: var(--cyan);
}

.form__note {
  font-size: 12px;
  line-height: 1.7;
  color: var(--muted);
}

/* 登录失败提示。用亮红而不是页面主色青 —— 这是"出错了"，不该跟品牌色混在一起 */
.form__error {
  margin: 12px 0 0;
  font-size: 12.5px;
  line-height: 1.7;
  color: #ff6b6b;
}

.form__submit {
  width: 100%;
  /* 比站点默认的 52px 矮一档，给卡片省一点高度 */
  height: 46px;
  /* 压在卡片底部（原因见 .formset 那条注释） */
  margin-top: auto;
}

/* 周期性掠过按钮的高光（同 admin 的 sheen） */
.form__sheen {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 42%;
  background: linear-gradient(100deg, transparent, rgba(255, 255, 255, 0.75), transparent);
  transform: translateX(-150%) skewX(-18deg);
  animation: acct-sheen 3.6s var(--ease-soft) infinite;
}

@keyframes acct-sheen {
  0% {
    transform: translateX(-150%) skewX(-18deg);
  }
  55%,
  100% {
    transform: translateX(260%) skewX(-18deg);
  }
}

/* 加载中：叠一层流动斜纹，替代转圈图标 */
.form__submit:disabled::after {
  content: '';
  position: absolute;
  inset: 0;
  background: repeating-linear-gradient(
    115deg,
    rgba(4, 18, 26, 0.16) 0 10px,
    transparent 10px 22px
  );
  animation: acct-stripes 0.6s linear infinite;
}

@keyframes acct-stripes {
  to {
    transform: translateX(22px);
  }
}

/* ---------------------------- 第三方登录 ---------------------------- */
.third {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 16px;
  align-items: stretch;
}

/* 二维码占位：素材还没有，先按联系区那张卡的做法写成明确的占位态 */
.third__qr {
  display: grid;
  place-items: center;
  width: 150px;
  height: 150px;
  border: 1px dashed rgba(255, 255, 255, 0.18);
  border-radius: 16px;
  font-size: 12px;
  letter-spacing: 0.18em;
  color: var(--muted);
}

.third__col {
  display: grid;
  gap: 12px;
  align-content: start;
}

.third__btn {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  grid-template-rows: auto auto;
  column-gap: 12px;
  row-gap: 2px;
  align-items: center;
  padding: 12px 16px;
  border: 1px solid var(--line-strong);
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.03);
  color: var(--text);
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition: border-color 0.35s var(--ease), background 0.35s var(--ease),
    transform 0.35s var(--ease);
}

.third__btn:hover {
  border-color: rgba(110, 231, 255, 0.5);
  background: rgba(110, 231, 255, 0.06);
  transform: translateY(-2px);
}

.third__icon {
  grid-row: span 2;
  width: 26px;
  height: 26px;
  fill: none;
  stroke: rgba(110, 231, 255, 0.75);
  stroke-width: 1.4;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.third__dot {
  fill: currentColor;
  stroke: none;
}

.third__btn:hover .third__icon {
  stroke: var(--cyan);
}

.third__label {
  font-size: 13.5px;
  letter-spacing: 0.06em;
}

.third__hint {
  font-size: 11.5px;
  letter-spacing: 0.02em;
  color: var(--muted);
}

/* ---------------------------- 已登录：面板头部 ---------------------------- */
.panel__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: clamp(20px, 3vw, 44px);
}

.panel__intro {
  max-width: 620px;
}

.who {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 11px 12px 11px 11px;
  border: 1px solid var(--line);
  border-radius: 99px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.045), rgba(255, 255, 255, 0.012));
}

.who__avatar {
  flex: none;
  width: 42px;
  height: 42px;
  border-radius: 50%;
  border: 1px solid rgba(255, 255, 255, 0.16);
}

/* 会员等级：跟身份胶囊挤在一行，做小一号的金色标签 */
.who__tier {
  margin-left: 4px;
  padding: 2px 8px;
  border: 1px solid rgba(242, 209, 141, 0.34);
  border-radius: 99px;
  font-size: 10.5px;
  letter-spacing: 0.06em;
  color: var(--gold);
  background: rgba(242, 209, 141, 0.1);
}

.who__meta {
  min-width: 0;
}

.who__name {
  font-size: 14px;
  line-height: 1.4;
}

.who__mail {
  font-size: 12.5px;
  line-height: 1.5;
  color: var(--muted);
}

.who__out {
  flex: none;
  margin-left: 6px;
  padding: 7px 15px;
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

.who__out:hover {
  color: var(--text);
  border-color: var(--cyan);
}

/* ---------------------------- 已登录：四块面板 ---------------------------- */
.grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  /* 紧凑化：卡片之间、卡片内部都收一档 —— 之前一屏塞不下几块内容，空得慌 */
  gap: clamp(10px, 1.2vw, 14px);
  margin-top: clamp(22px, 2.4vw, 32px);
}

.tile {
  position: relative;
  padding: clamp(16px, 1.7vw, 22px);
  border: 1px solid var(--line);
  border-radius: 20px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.035), rgba(255, 255, 255, 0.008));
  overflow: hidden;
  transition: border-color 0.5s var(--ease);
}

.tile:hover {
  border-color: var(--line-strong);
}

.tile--wide {
  grid-column: 1 / -1;
}

.tile__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
}

.tile__title {
  font-size: 12.5px;
  font-weight: 500;
  letter-spacing: 0.24em;
  text-transform: uppercase;
  color: var(--muted);
}

.tile__count {
  font-family: var(--mono);
  font-size: 12.5px;
  color: var(--text-dim);
}

.tile__note {
  margin-top: 16px;
  font-size: 12.5px;
  line-height: 1.8;
  color: var(--muted);
}

/* ---------------------------- 授权列表 ----------------------------
   一份授权一行，点开才是明细 —— 与设备卡同一套折叠手法
   （grid-template-rows 0fr → 1fr，收起时不写死高度）。
   后期版本变多，只是这个列表变长，结构与样式都不用动。 */
.lics {
  margin: 20px 0 0;
  padding: 0;
  list-style: none;
}

.lic-row {
  border-top: 1px solid var(--line);
}

.lic-row:last-child {
  border-bottom: 1px solid var(--line);
}

/* 过期的那份整体压暗，但不禁用 —— 授权码这些还是能看能复制的 */
.lic-row.is-expired .lic-row__head {
  opacity: 0.55;
}

.lic-row__head {
  display: flex;
  align-items: center;
  gap: 13px;
  width: 100%;
  padding: 12px 0;
  border: 0;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition: opacity 0.4s var(--ease);
}

/* 圆点标状态：青=生效中，金=即将到期，灰=已过期 */
.lic-row__dot {
  flex: none;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #3a4157;
}

.lic-row__dot.is-live {
  background: var(--cyan);
  box-shadow: 0 0 0 4px rgba(110, 231, 255, 0.14);
}

.lic-row__dot.is-soon {
  background: var(--gold);
  box-shadow: 0 0 0 4px rgba(242, 209, 141, 0.16);
}

.lic-row__main {
  flex: 1;
  min-width: 0;
}

.lic-row__product {
  display: block;
  font-size: 14.5px;
  transition: color 0.35s var(--ease);
}

.lic-row__edition {
  display: block;
  margin-top: 4px;
  font-size: 12px;
  color: var(--muted);
}

.lic-row__caret {
  flex: none;
  width: 18px;
  height: 18px;
  color: var(--muted);
  transition: transform 0.45s var(--ease), color 0.35s var(--ease);
}

.lic-row__head:hover .lic-row__product,
.lic-row__head:hover .lic-row__caret {
  color: var(--cyan);
}

.lic-row.is-open .lic-row__caret {
  transform: rotate(180deg);
  color: var(--cyan);
}

.lic-row__body {
  display: grid;
  grid-template-rows: 0fr;
  transition: grid-template-rows 0.45s var(--ease);
}

.lic-row.is-open .lic-row__body {
  grid-template-rows: 1fr;
}

.lic-row__body-in {
  overflow: hidden;
}

/* 明细：授权码占满一行，有效期 / 剩余 / 席位 并排一行 —— 三列刚好两行填满，不留空格子 */
.lic-fields {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  padding: 2px 0 16px;
}

.lic-field {
  display: grid;
  gap: 6px;
  padding: 11px 13px;
  border: 1px solid var(--line);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.025);
}

.lic-field--wide {
  grid-column: 1 / -1;
}

.lic-field__k {
  font-size: 11.5px;
  letter-spacing: 0.14em;
  color: var(--muted);
}

.lic-field__v {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  font-size: 13px;
  color: var(--text);
}

.lic-field__v--mono {
  font-family: var(--mono);
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.06em;
}

.lic-field__code {
  flex: 1;
  min-width: 0;
  font-family: var(--mono);
  font-size: 13px;
  letter-spacing: 0.08em;
  word-break: break-all;
}

.bar {
  height: 4px;
  border-radius: 99px;
  background: rgba(255, 255, 255, 0.08);
  overflow: hidden;
}

.bar i {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--grad);
}

.lic__hint {
  margin: 10px 0 18px;
  font-size: 12px;
  color: var(--muted);
}

/* ---------------------------- 设备 + EA 表现（融合进授权） ---------------------------- */
.ea {
  margin: 0 0 18px;
  padding: 14px;
  border: 1px solid var(--line);
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.02);
}

.ea__device {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 13px;
  padding-bottom: 12px;
  border-bottom: 1px solid var(--line);
}

.ea__dot {
  flex: none;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #3a4157;
}

.ea__dot.is-on {
  background: var(--cyan);
  box-shadow: 0 0 0 4px rgba(110, 231, 255, 0.14);
}

.ea__device-text {
  font-size: 13px;
  color: var(--text-dim);
}

.ea__device-id {
  margin-left: auto;
  font-family: var(--mono);
  font-size: 12px;
  letter-spacing: 0.06em;
  color: var(--muted);
}

.ea__report {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 18px;
}

.ea__pnl {
  display: flex;
  align-items: baseline;
  gap: 12px;
}

.ea__pnl-k {
  font-size: 12px;
  letter-spacing: 0.12em;
  color: var(--muted);
}

.ea__pnl-v {
  font-family: var(--mono);
  font-size: clamp(20px, 2vw, 26px);
  font-weight: 500;
  font-variant-numeric: tabular-nums;
  letter-spacing: -0.01em;
  color: var(--cyan);
  text-shadow: 0 0 22px rgba(110, 231, 255, 0.32);
}

.ea__stats {
  display: flex;
  gap: 20px;
}

.ea__stat {
  display: inline-flex;
  align-items: baseline;
  gap: 6px;
  font-family: var(--mono);
  font-variant-numeric: tabular-nums;
  font-size: 14px;
}

.ea__stat-k {
  font-size: 12px;
  color: var(--muted);
}

.ea__stat em {
  font-style: normal;
  font-weight: 500;
  font-size: 15px;
}

.ea__stat em.up {
  color: var(--cyan);
}

.ea__stat em.down {
  color: #ff9b9b;
}

.ea__stat .sep {
  color: var(--muted);
  font-size: 12px;
}

/* 详情按钮：胶囊 + 渐变描边，hover 时填充 */
.ea__detail {
  margin-left: auto;
  padding: 8px 20px;
  border: 1px solid rgba(110, 231, 255, 0.45);
  border-radius: 99px;
  background: rgba(110, 231, 255, 0.08);
  color: var(--cyan);
  font: inherit;
  font-size: 13px;
  letter-spacing: 0.1em;
  cursor: pointer;
  transition: background 0.4s var(--ease), color 0.4s var(--ease), border-color 0.4s var(--ease),
    box-shadow 0.4s var(--ease);
}

.ea__detail:hover {
  background: var(--grad);
  border-color: transparent;
  color: #06070d;
  box-shadow: 0 10px 26px -12px rgba(110, 231, 255, 0.9);
}

.ea__demo {
  margin: 0;
  font-size: 12.5px;
  color: var(--muted);
}

/* 设备 / 下载共用一套行 */
.rows {
  margin: 20px 0 0;
  padding: 0;
  list-style: none;
}

.row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 0;
  border-top: 1px solid var(--line);
}

.row:last-child {
  border-bottom: 1px solid var(--line);
}

.row__dot {
  flex: none;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #3a4157;
}

.row__dot.is-on {
  background: var(--cyan);
  box-shadow: 0 0 0 4px rgba(110, 231, 255, 0.14);
}

.row__tag {
  flex: none;
  min-width: 40px;
  padding: 3px 8px;
  border: 1px solid var(--line);
  border-radius: 6px;
  text-align: center;
  font-size: 11px;
  letter-spacing: 0.1em;
  color: var(--muted);
}

.row__main {
  flex: 1;
  min-width: 0;
}

.row__os {
  font-size: 14px;
}

.row__id,
.row__file {
  display: block;
  margin-top: 3px;
  font-family: var(--mono);
  font-size: 12px;
  letter-spacing: 0.06em;
  color: var(--muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.row__file {
  font-size: 13px;
  color: var(--text-dim);
}

.row__meta {
  margin-top: 3px;
  font-size: 12px;
  color: var(--muted);
}

/* 订单：五列，窄屏横向滚动（列语义固定，拆行反而更难读） */
.orders {
  margin-top: 20px;
  overflow-x: auto;
}

.orders__row {
  display: grid;
  grid-template-columns: 208px minmax(180px, 1fr) 118px 116px 96px;
  gap: 14px;
  align-items: center;
  min-width: 700px;
  padding: 13px 0;
  border-top: 1px solid var(--line);
  font-size: 13.5px;
}

.orders__row:last-child {
  border-bottom: 1px solid var(--line);
}

.orders__row--head {
  padding-top: 0;
  border-top: 0;
  font-size: 12px;
  letter-spacing: 0.16em;
  color: var(--muted);
}

.orders__row code {
  font-family: var(--mono);
  font-size: 12.5px;
  letter-spacing: 0.06em;
  color: var(--text-dim);
}

/* ---------------------------- 切换动效 ---------------------------- */

/* 两态之间（未登录 ↔ 面板）：整块淡出淡入 */
.swap-enter-active {
  transition: opacity 0.45s var(--ease), transform 0.45s var(--ease);
}

.swap-leave-active {
  transition: opacity 0.22s var(--ease);
}

.swap-enter-from {
  opacity: 0;
  transform: translateY(14px);
}

.swap-leave-to {
  opacity: 0;
}

/* ---------------------------- 响应式与降级 ---------------------------- */
@media (max-width: 980px) {
  .auth__body {
    grid-template-columns: minmax(0, 1fr);
  }

  .glass {
    max-width: 480px;
  }

  .grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .lic {
    grid-template-columns: minmax(0, 1fr);
  }

  .lic__side {
    padding-left: 0;
    padding-top: 20px;
    border-left: 0;
    border-top: 1px solid var(--line);
  }

  .panel__head {
    flex-direction: column;
    align-items: flex-start;
  }
}

/* 窄到放不下横排第三方按钮时改竖排 */
@media (max-width: 460px) {
  .third {
    grid-template-columns: minmax(0, 1fr);
  }

  .third__qr {
    width: 100%;
    height: 130px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .mini,
  .tabs__btn,
  .ways__btn,
  .fld__input,
  .fld__code,
  .form__link,
  .third__btn,
  .who__out,
  .tile,
  .perks__item::before,
  .perks__no,
  .perks__title,
  .perks__desc {
    transition: none;
  }

  .swap-enter-active,
  .swap-leave-active {
    transition: none;
  }

  .swap-enter-from {
    transform: none;
  }

  /* 装饰性动画：流光、掠光、斜纹、入场，一律停掉 */
  .glass::before,
  .form__sheen,
  .form__submit:disabled::after,
  .formset {
    animation: none;
  }

  .formset {
    opacity: 1;
    transform: none;
  }

  .pane {
    transition: none;
  }
}

/* ==========================================================================
   已登录：概览条 / 博客 / 论坛 / 积分商城
   （追加在末尾，不与上面未登录区的样式冲突）
   ========================================================================== */

/* ---------------------------- 概览：三个实时数据 ---------------------------- */
/* 当前时间 / 最近到期的授权倒计时 / 积分 */
.metrics {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: clamp(10px, 1.2vw, 14px);
  /* 与上面的标题区（.panel__head）拉开 */
  margin: clamp(20px, 2.2vw, 28px) 0 0;
  padding: 0;
  list-style: none;
}

.metrics__item {
  display: grid;
  gap: 6px;
  padding: 13px 15px;
  border: 1px solid var(--line);
  border-radius: 16px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.01));
}

.metrics__k {
  font-size: 11.5px;
  letter-spacing: 0.16em;
  color: var(--muted);
}

.metrics__k--link {
  justify-self: start;
  transition: color 0.35s var(--ease);
}

.metrics__k--link:hover {
  color: var(--cyan);
}

.metrics__v {
  font-size: clamp(17px, 1.7vw, 22px);
  color: var(--text);
}

.metrics__v--mono {
  font-family: var(--mono);
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.04em;
}

.metrics__v--gold {
  font-weight: 500;
  color: var(--gold);
}

/* ---------------------------- 博客 / 论坛列表 ---------------------------- */
.tile__more {
  font-size: 12.5px;
  color: var(--muted);
  transition: color 0.35s var(--ease);
}

.tile__more:hover {
  color: var(--cyan);
}

.feed {
  margin: 20px 0 0;
  padding: 0;
  list-style: none;
}

.feed__item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 0;
  border-top: 1px solid var(--line);
}

.feed__item:last-child {
  border-bottom: 1px solid var(--line);
}

/* 圆点区分来源：青=已发布，灰=草稿，紫=自己发过或回过的 */
.feed__dot {
  flex: none;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--cyan);
  box-shadow: 0 0 0 3px rgba(110, 231, 255, 0.12);
}

.feed__dot.is-draft {
  background: #3a4157;
  box-shadow: none;
}

.feed__dot.is-mine {
  background: var(--violet);
  box-shadow: 0 0 0 3px rgba(167, 139, 250, 0.14);
}

.feed__main {
  flex: 1;
  min-width: 0;
}

.feed__title {
  font-size: 14px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  transition: color 0.35s var(--ease);
}

.feed__item:hover .feed__title {
  color: var(--cyan);
}

.feed__meta {
  margin-top: 4px;
  font-size: 12px;
  color: var(--muted);
}

.feed__time {
  flex: none;
  font-family: var(--mono);
  font-size: 12px;
  letter-spacing: 0.04em;
  color: var(--muted);
}

/* ---------------------------- 积分商城 ---------------------------- */
.store {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: clamp(10px, 1.2vw, 14px);
  margin: 16px 0 0;
  padding: 0;
  list-style: none;
}

.store__item {
  display: grid;
  gap: 6px;
  align-content: start;
  padding: 15px;
  border: 1px solid var(--line);
  border-radius: 16px;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.035), rgba(255, 255, 255, 0.008));
  transition: border-color 0.45s var(--ease), transform 0.45s var(--ease);
}

.store__item:hover {
  border-color: var(--line-strong);
  transform: translateY(-3px);
}

/* 积分不够的：整块压暗，按钮置灰，不给人点了没反应的错觉 */
.store__item.is-locked {
  opacity: 0.55;
}

.store__item.is-locked:hover {
  transform: none;
}

.store__tag {
  justify-self: start;
  padding: 3px 9px;
  border: 1px solid var(--line);
  border-radius: 6px;
  font-size: 11px;
  letter-spacing: 0.1em;
  color: var(--muted);
}

.store__name {
  font-size: 15px;
  font-weight: 500;
}

.store__note {
  font-size: 12.5px;
  line-height: 1.7;
  color: var(--muted);
}

.store__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 6px;
  padding-top: 12px;
  border-top: 1px solid var(--line);
}

.store__cost {
  font-family: var(--mono);
  font-size: 13px;
  color: var(--gold);
}

@media (max-width: 760px) {
  .metrics {
    grid-template-columns: minmax(0, 1fr);
  }

  .lic-fields {
    grid-template-columns: minmax(0, 1fr);
  }
}

@media (prefers-reduced-motion: reduce) {
  .metrics__k--link,
  .feed__title,
  .store__item,
  .tile__more {
    transition: none;
  }

  .store__item:hover {
    transform: none;
  }
}
</style>
