# 模块 1 · Auth（管理端鉴权）

> 文档版本：`0.4.0` · 最后更新：`2026-10-03`

## 状态

**一期完成**：登录、登出、当前登录者、限流、主体隔离，全部已实现并实测。

## 职责

- 后台管理者的**登录**（令牌签发）、**登出**（令牌吊销）
- 提供"当前登录者"接口供 admin 前端做页面初始化
- 保证**前台会员的令牌无法访问后台接口**（主体隔离）

**不负责**：

- 管理端登录页 UI（那是 `admin/` 前端的事）
- 前台会员的注册 / 登录（那是**模块 07 Member**，另一张表、另一个 guard）
- EA 客户端的鉴权（那是**模块 04 Client**，走签名而非令牌）
- 角色权限判定（那是**模块 08 Rbac**；本模块只管"你是谁"，不管"你能干什么"）

## 对外接口

全部已实现。统一响应体外壳见 [00-support.md](00-support.md)，下表只写出参的 `data` 部分。

| 方法 | 路径 | 鉴权 | `data` 出参 | 状态 |
|---|---|---|---|---|
| POST | `/api/v1/admin/login` | 无 + **限流** | `{ token, admin: {...} }` | ✅ |
| POST | `/api/v1/admin/logout` | `auth:admin` | `{}` | ✅ |
| GET | `/api/v1/admin/me` | `auth:admin` | `{ admin: {...} }` | ✅ |

`admin` 对象的字段（由 `AdminResource` 决定）：

```json
{ "id": 1, "username": "root", "name": "超级管理员",
  "email": null, "status": "active", "last_login_at": "2026-10-03T10:27:20+00:00" }
```

> 登录与 `/me` 都把管理员信息**包在 `admin` 键里**，前端两处可以共用同一段解析代码（`body.data.admin`）。
> `last_login_at` 是 **UTC（`+00:00`）**，前端按本地时区格式化。

### 请求形态

```
POST /api/v1/admin/login
Content-Type: application/json
{ "username": "root", "password": "……", "device_name": "chrome" }
```

`device_name` 可省略（默认 `admin`），它只是令牌的备注名，方便后台列出"哪些设备登录过"。

> **登录标识是 `username`，不是 `email`。** 这是与已建好的 `admin/src/views/LoginView.vue`
> 对齐的结果（前端发的就是 `username`）。`email` 保留可空、仅用于通知。
> 若以后要支持邮箱登录，在 `AdminAuthService::login()` 里加一条 `orWhere` 即可。

### 错误码一览

| 场景 | 错误码 | HTTP |
|---|---|---|
| 账号不存在 **或** 密码错（**不区分**） | `AUTH_INVALID_CREDENTIALS` | 401 |
| 账号被禁用 | `AUTH_ACCOUNT_DISABLED` | 403 |
| 缺字段 / 格式不对 | `SYS_VALIDATION`（明细在 `meta.errors`） | 422 |
| 同账号同 IP 每分钟超过 5 次 | `SYS_RATE_LIMITED` | 429 |
| 无令牌 / 令牌无效 / 令牌已注销 / **主体不对** | `SYS_UNAUTHENTICATED` | 401 |

## 关键类与函数

| 位置 | 类 / 方法 | 说明 |
|---|---|---|
| `app/Modules/Auth/Http/Controllers/AdminAuthController.php` | `login()` / `logout()` / `me()` | 只做 HTTP 翻译，不写业务分支 |
| `app/Modules/Auth/Http/Requests/AdminLoginRequest.php` | `rules()` | 入参形状校验。**不重写 `failedValidation()`** —— 全局已统一 |
| `app/Modules/Auth/Services/AdminAuthService.php` | `login(username, password, deviceName, ip)` | 业务规则本体：校验、状态判断、签令牌、记登录时间 |
| 同上 | `logout(Admin)` | 只吊销**当前**令牌 |
| `app/Modules/Auth/Http/Resources/AdminResource.php` | `toArray()` | 出参整形，逐字段显式列出（防字段泄漏） |
| `app/Modules/Auth/Models/Admin.php` | `Admin` | `admins` 表映射 + `HasApiTokens` |
| `app/Modules/Auth/Enums/AdminStatus.php` | `canLogin()` / `label()` | 状态判断收在枚举上 |
| `app/Providers/AppServiceProvider.php` | `configureRateLimiting()` | 定义 `admin-login` 限流规则 |
| `database/seeders/AdminSeeder.php` | `run()` | 建初始账号，**幂等、不覆盖已有密码** |

### 登录接口里的三条安全处理（改代码时不要动）

1. **账号不存在与密码错返回同一个错误码**，否则攻击者能靠错误码差异枚举出哪些账号存在。
2. **状态检查放在密码校验之后**。反过来等于"不用密码就能问出某账号是否存在且被禁用"。
3. **账号不存在时也跑一次 `Hash::check`**（用临时生成的哈希），把两条分支耗时拉平 ——
   否则响应快慢本身就是枚举信号。

### ⚠️ 守卫必须写 `auth:admin`，不能写 `auth:sanctum`

Sanctum 的 Guard 用 `hasValidProvider()` 校验主体是不是该 provider 的模型
（`vendor/laravel/sanctum/src/Guard.php:130,145-153`），
**而 `provider` 为 `null` 时它直接 `return true`**（`:147-149`）。
Sanctum 自动注册的 `sanctum` 守卫正是 `provider = null` —— 用它等于不设防。

改造前的实际状况是：**前台会员的令牌可以直接读 `/api/v1/admin/me`**。
现在由 `tests/Feature/Auth/GuardIsolationTest.php` 钉住这三条：

```
管理员令牌 → 200
会员令牌   → 401 SYS_UNAUTHENTICATED   ← 改造前这里是 200
伪造令牌   → 401
```

## 数据表

| 表 | 迁移 | 关键字段 |
|---|---|---|
| `admins` | `2026_10_03_140000_create_admins_table` | `username`(唯一，登录用) `name` `email`(可空) `password` `status` `last_login_at` `last_login_ip` |
| `personal_access_tokens` | `2026_10_03_082601_...` | `tokenable_type/id` `name` `token`(哈希) `abilities` `expires_at` `last_used_at` |

`users`（前台会员）是**另一张表**，由模块 07 负责；两表不共用、不互查（需求 D1）。

## 配置与环境变量

| 配置 | 说明 |
|---|---|
| `config/auth.php` 的 `guards.admin` | **改这里等于改全后台的鉴权方式**，动之前先跑 `GuardIsolationTest` |
| `ADMIN_SEED_USERNAME` / `ADMIN_SEED_PASSWORD` | 可选。不配则用下面那组默认值 |
| `config/sanctum.php` | 令牌前缀、过期时间 |
| `SESSION_DRIVER=array` | 我们走令牌，**不用** Cookie 会话，所以不需要 `sessions` 表 |

### 本地开发账号（弱密码，仅供测试）

```
账号：root
密码：11111
```

密码定义在 `AdminSeeder::DEFAULT_PASSWORD`。**`DatabaseSeeder` 里那个示例会员账号用的是同一个密码**
（本地所有账号统一一个密码，测试时不用记两套）。

⚠️ **`AdminSeeder` 是幂等的，改了默认密码不会影响已存在的账号。** 老账号要手动改：

```php
// php artisan tinker
Admin::where('username', 'root')->first()->forceFill(['password' => '新密码'])->save();
// 直接给明文，hashed cast 负责哈希 —— 不要再 Hash::make()
```

**上线前必须换掉这个密码，或者干脆不在生产环境执行这个 Seeder。**

## 测试

| 文件 | 覆盖 |
|---|---|
| `tests/Feature/Auth/AdminLoginTest.php` | 登录成功/密码错/账号不存在/禁用/校验失败/记登录时间/令牌可用/登出吊销/多设备隔离/限流 |
| `tests/Feature/Auth/GuardIsolationTest.php` | **越权防线**：管理员 200、会员 401、伪造 401 |

> ⚠️ 测"登出后失效"时，两次请求之间必须调 `$this->forgetAuthenticatedUser()`。
> 一个测试方法里的多次请求复用同一个应用实例，而 `RequestGuard::user()` 会缓存用户，
> 不清就会测出"令牌没吊销"的**假结果**。原因与影响见 `tests/ApiTestCase.php` 的注释。

## 待办

- [ ] 令牌有效期策略（永久 / 30 天 / 滑动续期）
- [ ] 令牌管理页接口（列出 / 吊销其他设备）
- [ ] 登录失败写审计日志（模块 05 Audit 落地后接上）
- [ ] 单账号跨 IP 的失败次数累计封禁（现在只按"账号 + IP"限流）
- [ ] `/me` 与登录出参补 `abilities`（权限标识），等模块 08 Rbac
