# 模块 07 · 前台会员（Member）

> 文档版本：`0.4.0` · 最后更新：`2026-10-03`

## 状态

**一期完成，且已与 face 前端联调打通**：注册 / 登录 / 登出 / 当前会员、`auth:member` 守卫、
限流、等级与积分、**手机号登录**，全部已实现并实测。
二维码 / 短信（验证码）登录与**注册接口的前端对接**为待做项。

## 职责

- 前台**会员**的注册、登录、登出、当前会员信息
- 给个人中心（Profile）与博客、论坛提供"当前登录的会员"这个主体
- 注册后默认身份是**普通用户**（`role = user`，等 Rbac 落地后自动挂）

**不负责**：

- 后台**管理者**的登录（那是模块 01 Auth，`admins` 表）
- 权限判定逻辑（那是模块 08 Rbac）
- 授权 / 激活码 / 设备（那是模块 03 License 与 04 Client）
- 会员个人中心的页面与业务（那是模块 13 Profile）

## 关键架构决策（用户已拍板）

1. **会员就是 `users` 总表**，不新建 `members` 表。
   模型用框架默认的 `App\Models\User`（已 `HasApiTokens`），Member 模块只加服务/接口/资源/守卫。
2. **总表 + 分表**：`users` 是所有"人"，`admins` 是管理员分表（将来 `admins.user_id` 挂回）。
   见 requirements.md 的「用户表架构」。
3. **登录方式**（需求 C-6）：一期只做**手机号 / 邮箱 + 密码**；
   二维码（微信 / Telegram）、短信（验证码）为**预留接口**，后做。

## 对外接口

| 方法 | 路径 | 鉴权 | `data` 出参 | 状态 |
|---|---|---|---|---|
| POST | `/api/v1/member/register` | 无 + **限流** | `{ token, member: {...} }` | ✅ 后端 / ⛔ 前端未接 |
| POST | `/api/v1/member/login` | 无 + **限流** | `{ token, member: {...} }` | ✅ 已联调 |
| POST | `/api/v1/member/logout` | `auth:member` | `{}` | ✅ 已联调 |
| GET | `/api/v1/member/me` | `auth:member` | `{ member: {...} }` | ✅ 已联调 |

`member` 对象字段（`MemberResource`）：

```json
{ "id": 2, "name": "南门会员", "email": "test@example.com", "phone": "11111",
  "status": "active", "tier": "silver", "points": 2860,
  "email_verified_at": null, "last_login_at": "…", "created_at": "…" }
```

### 请求形态

```
POST /api/v1/member/login
{ "phone": "11111", "password": "11111", "device_name": "web" }   ← 手机号登录
{ "email": "a@b.com", "password": "11111" }                        ← 邮箱登录
```

**手机号与邮箱二选一**（`required_without`），两个都传时以 `phone` 为准。
`phone` **不做数字校验** —— 它在测试期只是一个登录标识（统一填 `11111`），
真接短信登录时格式校验要加在"发验证码"那一步。

### 错误码一览（与 admin 共用同一套）

| 场景 | 错误码 | HTTP |
|---|---|---|
| 账号不存在 **或** 密码错（不区分） | `AUTH_INVALID_CREDENTIALS` | 401 |
| 账号被禁用 | `AUTH_ACCOUNT_DISABLED` | 403 |
| 邮箱重复 / 两次密码不一致 / 缺字段 | `SYS_VALIDATION`（明细在 `meta.errors`） | 422 |
| 同账号同 IP 每分钟超 5 次 | `SYS_RATE_LIMITED` | 429 |
| 无令牌 / 无效 / 已注销 / **主体不对** | `SYS_UNAUTHENTICATED` | 401 |

## 前端对接（`face/`，已实测打通）

| 前端文件 | 干什么 |
|---|---|
| `nuxt.config.ts` | `runtimeConfig.public.apiBase`（默认 `http://127.0.0.1:8000`，可用 `NUXT_PUBLIC_API_BASE` 覆盖） |
| `app/composables/useApi.ts` | 薄封装：统一 base、Bearer、**拆统一响应体的信封**、把失败包成 `ApiError` |
| `app/composables/useAccount.ts` | 登录态单例：`login()` / `logout()` / `restoreSession()` + 本地会话 |
| `app/pages/account.vue` | 用户中心。登录字段表 `F` + `SCHEME` 已把"验证码"换成**密码**，`submit()` 调真实登录 |
| `app/components/SiteHeader.vue` | 页头账户入口；`onMounted` 里调 `restoreSession()`，刷新后头像立刻回来 |

### 本地会话（会话持久化 + 超时）

- 存 `localStorage` 的 **`nmnx.member.session`**，形如 `{ token, expiresAt, member }`
- **有效期 4 小时**（`useAccount.ts` 里的 `SESSION_TTL`）
- 到点由共享时钟的每秒心跳**自动登出**（只清本地，不发请求）

### ⚠️ 「刷新时闪一下登录页」这个坑（已修，别改回去）

**现象**：已登录的人按 F5，会先看到登录卡、再跳到账户面板。

**根因**（两层）：
1. `logged` 在**服务端渲染时必然是 `false`**（服务端读不到 localStorage），
   所以首屏 HTML 画的就是登录卡；
2. 等注水后 `restoreSession()` 才发 `/member/me`，**一个网络往返**之后才切成面板 ——
   而两态外面还套着 `<Transition name="swap" mode="out-in">`，登录卡会被"淡出"，
   看起来就像跳了一下。

**修法**：把"恢复会话"拆成**同步段 + 异步段**，并引入 `sessionReady`：

| 阶段 | 做什么 |
|---|---|
| 服务端渲染 | `sessionReady = false` → **什么都不画**（既不是登录卡、也不是面板、也没有占位框） |
| 注水后的 `onMounted` | `restoreSession()` 的**同步段**：读本地会话（同步可读！）→ 立刻定下 `logged` / `sessionReady`，**没有任何 await** |
| 同一 tick | 界面首次确定 → 画正确的那一个（面板）。此时 `<Transition>` 才被创建，初次挂载不走进场动画，所以直接就是对的页面 |
| 之后 | **异步段**发 `/member/me` 核对并刷新资料；401 才清会话，网络错误**保留**本地登录态 |

配套两点：
- 本地会话里**多存一份 `member`**（缓存资料）—— 只为"刷新后第一帧就有正确内容"，
  否则会先画一帧空名字 / 0 积分的面板。它随后总会被 `/member/me` 覆盖。
- `SiteHeader` 用一个与头像同尺寸的 `.hdr__pending` 占位（**不可见**），刷新时不再闪"登录/注册"。

> **为什么是"空"而不是占位框**：占位框（"正在恢复登录状态…"）用户明确不要。
> 空着时页面背景与辉光仍在，看着就是"内容还没到"，比一个框自然。
> **代价**：这一瞬间没有内容。想连这一瞬间都消掉，只能让**服务端也读得到登录态** ——
> 即把会话从 `localStorage` 挪到 **cookie**，再用 `useState`（不能用模块级 ref，
> 那在 SSR 下是跨请求共享的）承接，让服务端直接把面板渲染出来。属后续优化，见待办。

**判据（可复现）**：`curl http://localhost:3000/account` 的 HTML 里
**不该出现** `hdr__login` / `创建账户` / `third__btn` / `pending__text`，
**应该出现** `hdr__pending`。
⚠️ 检查要用**元素标记**（class 名），不要查裸文案 —— Vue SSR 开发模式会把**模板注释**
也输出到 HTML 里，注释里提到的词会造成假阳性（这个坑踩过两次）。

> ⚠️ 这 4 小时目前**只在浏览器端生效**。服务端令牌本身还没设过期时间
> （`config/sanctum.php` 的 `expiration` 走 `SANCTUM_EXPIRATION`）。要服务端也强制到期，
> 在 `.env` 里设 `SANCTUM_EXPIRATION=240` 即可 —— 注意它**对所有令牌生效（含 admin）**。

## 关键类与函数

| 位置 | 类 / 方法 | 说明 |
|---|---|---|
| `App\Modules\Member\Http\Controllers\MemberAuthController` | `register/login/logout/me` | 只做 HTTP 翻译 |
| `App\Modules\Member\Http\Requests\MemberLoginRequest` | `rules()` | 手机号 / 邮箱二选一 + 密码 |
| `App\Modules\Member\Http\Requests\MemberRegisterRequest` | `rules()` | 邮箱唯一 + 密码 `confirmed` |
| `App\Modules\Member\Services\MemberAuthService` | `login(?email, ?phone, password, …)` | 业务规则本体（防枚举 / 时序防护 / 状态后判） |
| `App\Modules\Member\Http\Resources\MemberResource` | `toArray()` | 出参整形，**不吐 password**；`tier` 出**代码**不是中文 |
| `App\Modules\Member\Enums\MemberStatus` | `Active` / `Disabled` / `canLogin()` | 会员状态枚举 |
| `App\Models\User` | `User` | 会员模型 = users 总表模型 |
| `App\Providers\AppServiceProvider` | `configureRateLimiting()` | `member-login`（**标识**+IP，标识取 `phone` 优先、退回 `email`）、`member-register`（IP） |
| `database/seeders/MemberSeeder` | `run()` | 造测试会员 `11111 / 11111`（会重置，见下方） |

## 数据表

| 表 | 关键字段 |
|---|---|
| `users`（**总表**） | `id` `public_id`(唯一，`NMX-U-000002`) `name` `email`(唯一) `phone`(唯一、可空) `password` `status` `role_id`(Rbac) `tier` `points` `email_verified_at` `last_login_at` `last_login_ip` `last_seen_at` |
| `personal_access_tokens` | 与 admin **共用**，靠 `tokenable_type` 区分（`User` vs `Admin`） |

**三个和 Rbac 相关的点（模块 08 落地后新增）**：

1. **`status` 是三值**：`active`（正常）/ `muted`（**禁言：仍可登录**，只是不能发言）/ `banned`（封禁：不能登录）。
   `MemberStatus::canLogin()` 只拦 `banned`；发言另看 `canPost()`。禁言的人必须还能登录 ——
   否则他连自己的授权码都看不到。
2. **`public_id`**：注册时由模型钩子自动分配（自增 id 派生）。契约明确要求
   **不要把自增 id 显示给用户**，对外展示用 `public_id`。
3. **`role_id`**：注册即挂 `member` 角色。**没有角色的人一个权限都没有** ——
   连"发帖""投稿"这类前台能力也靠角色给（见 08-rbac.md）。

`tier` 存的是**等级代码**（`normal` / `silver` / `gold`），中文由前端 `useAccount.ts` 的
`TIER_LABELS` 映射 —— 接口不吐展示文案，改文案不用动后端。

## 守卫（第 4 层）

`config/auth.php` 的 `member` guard：`driver=sanctum` + `provider=users`。
与 admin 的隔离靠 Sanctum 的 `hasValidProvider()`，回归测试双向都覆盖：

```
管理员令牌 → /member/me  401   ← 反向越权
会员令牌   → /admin/me   401   ← 正向越权
```

## 测试账号（本地开发）

```
手机号：11111        密码：11111        （tier=silver，points=2860）
```

`php artisan db:seed --class=MemberSeeder` 造出来。
⚠️ 与 `AdminSeeder` 的保守策略不同：**它每次都会重置这个测试账号的密码与资料**
（`phone=11111` 不可能有真实用户，"每次都可登录"比"保护它"更重要）。

## 测试

`tests/Feature/Member/MemberAuthTest.php`（13 个用例）：注册成功 / 昵称兜底 / 邮箱重复 /
密码不一致 / 登录成功 / **手机号登录** / **缺标识** / 密码错 / 邮箱不存在（同码）/ 禁用 /
注册即登录 / 登出吊销 / 限流。
`tests/Feature/Auth/GuardIsolationTest.php`：补充"管理员令牌不能过 member 守卫"。

## 待办

- [ ] **消除刷新时那一瞬间的空**：把会话从 `localStorage` 挪到 **cookie**，
      让服务端渲染时也读得到登录态，直接把面板画出来（当前是"空一下再出面板"）。
      注意要同时把模块级 `ref` 换成 `useState` —— 模块级状态在 SSR 下是**跨请求共享**的，
      服务端一旦写它就会串号
- [ ] **注册接口的前端对接** + 后端支持手机号注册：
      需要把 `users.email` 改成**可空**（现在手机号注册会撞 email 的 NOT NULL）
- [ ] **二维码登录（微信 / Telegram）**：`loginByQr()`
- [ ] **短信 / 邮箱验证码登录**：`loginBySms()`（`users.phone` 已预留）
- [ ] 上线前补密码强度规则（一期为了本地测试 `11111` 故意不设 `min`）
- [ ] 服务端令牌过期（`SANCTUM_EXPIRATION`）—— 现在只有浏览器端 4 小时
- [ ] 与模块 08 Rbac 对接：注册后自动挂 `user` 角色
- [ ] `admins` 与 `users` 的挂接（`admins.user_id` + 存量回填）—— 属「用户模块 / Rbac」阶段
- [ ] 授权 / 设备 / 订单 / 战报的数据源 —— 属模块 03/04，前端现在仍吃占位数据
