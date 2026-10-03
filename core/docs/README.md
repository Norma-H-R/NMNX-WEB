# core —— 南门拈星 API 后端

`core` 是**纯 API 后端**：不渲染任何视图，所有请求都走 `/api/*`，返回 JSON。
两个前端（`face` 官网、`admin` 管理端）与 EA 客户端都通过这里的 API 拿数据。

- 框架：Laravel 13.17 / PHP 8.4（NTS VS17 x64）
- 数据库：开发用 SQLite（`database/database.sqlite`），后期迁 MySQL
- 缓存：Redis（`php_redis` 扩展 6.3.0，服务端 5.0.14.1）
- 鉴权：Sanctum Bearer 令牌（**不是** SPA Cookie 会话）

---

## 文档索引

| 文档 | 内容 |
|---|---|
| [standards.md](standards.md) | **开发要求文档（最高约束）**。代码/注释/文档规范、版本与日期规则、提交前自检清单。**动手前必读** |
| [CHANGELOG.md](CHANGELOG.md) | **变更记录**。每次操作都要追加，四要素：新增 / 修复 / 变更 / 验证 |
| [requirements.md](requirements.md) | **需求总清单**（带编号 `R*`，改需求说编号即可）。含"与已定设计冲突"和"待澄清"两节 |
| 本文 | 架构定位、四层守卫、模块地图、依赖方向 |
| [modules/](modules/) | 各模块文档，固定 7 小节；`_TEMPLATE.md` 是空白模板 |

---

## 四层守卫（入口边界）

加接口一律往对应层里加，**不要在别处另开入口**。定义在 `routes/api.php`。

| 前缀 | 使用者 | 守卫 | 状态 |
|---|---|---|---|
| `/api/v1/public` | 任何人（含爬虫） | 无 | 骨架已通 |
| `/api/v1/member` | **已登录的前台会员** | `auth:member`（provider = `users` 总表） | ✅ 注册 / 登录 / 登出 / me 已实现 |
| `/api/v1/admin` | admin 管理端（我们运营方） | **`auth:admin`**（provider = `admins`） | ✅ 登录 / 登出 / 限流已实现 |
| `/api/v1/client` | EA（MT4/MT5） | 签名 + 授权码 + 幂等 | 待做 |

> ⚠️ **每一层都必须是有 provider 的 guard，不能用 `auth:sanctum`。**
> Sanctum 自动注册的 `sanctum` 守卫 provider 是 `null`，而它遇到 `null` 会**放行任何令牌** ——
> 意味着会员令牌能读后台接口。详见 [modules/01-auth.md](modules/01-auth.md)。

> **会员为什么要独立一层**：会员和管理者是两套人（D1 已定两张表）。若都用同一个
> `auth:sanctum` 而不区分主体类型，会出现**会员令牌能访问 admin 接口**的越权。
> 用独立 guard 是结构性的防护，不依赖人记得挂中间件。

另有：
- `GET /api/v1/health` —— 探针
- `GET /up` —— Laravel 内置健康检查
- `GET /` —— 根探针（`routes/web.php`，只回 JSON，不渲染视图）

---

## 模块地图

模块代码放在 `app/Modules/<Name>/`，靠 `App\` 这个 PSR-4 前缀自动加载，**不需要改 composer.json**。
每个模块一份文档（`docs/modules/`），改模块前先看文档，不用翻代码。

需求来源见 [requirements.md](requirements.md)。**全站共 18 个模块 + 3 个平台系统**，
目录骨架已全部建出（`app/Modules/<Name>/`），按下面的顺序**逐一建出来**。

### 一期 —— 打通一条纵向切片（后台发文章 → 官网读到 + 会员能注册）

| # | 模块 | 文档 | 职责 | 状态 |
|---|---|---|---|---|
| 0 | Support | [00](modules/00-support.md) ✅ | 统一响应体 / 错误码 / 异常转 JSON —— 所有模块依赖它 | 部分实现 |
| 1 | Crypto 加密 | [06](modules/06-crypto.md) ✅ | 字段级加解密、密钥管理（预留 `key_id`）；横切所有模块。**一期只留接口，不实现算法** | 未开始 |
| 2 | Auth（admin） | [01](modules/01-auth.md) ✅ | **后台管理者**登录、令牌签发与吊销 | 守卫已通 |
| 3 | Member 会员 | [07](modules/07-member.md) ✅ | **前台会员**注册 / 登录（与 `admins` 两张表）。**需要新增第 4 层守卫** | 未开始 |
| 4 | Rbac 权限 | [08](modules/08-rbac.md) ✅ | 角色 / 权限 / 多态关联 —— **全站一套表** | 未开始 |
| 5 | Content | [02](modules/02-content.md) ✅ | 文章（**含可见性分级**）/ 宣传文案 | 未开始 |
| 6 | Menu 菜单管理 | [09](modules/09-menu.md) ✅ | 前台菜单与按钮的可配置化 | 未开始 |

### 二期 —— 社区（**本项目最难的两块**）

| # | 模块 | 文档 | 职责 | 状态 |
|---|---|---|---|---|
| 7 | Blog 博客 | [10](modules/10-blog.md) ⬜ | **难点**：长文、详情页可静态预渲染、按 tag 失效 | 未开始 |
| 8 | Forum 论坛 | [11](modules/11-forum.md) ⬜ | **难点**：高频写入、版块 + 版主（`board_moderators`）、详情页不做静态 | 未开始 |
| 9 | Feedback 反馈 | [12](modules/12-feedback.md) ⬜ | 评价与反馈 | 未开始 |

博客与论坛**互相可调**：论坛发帖时可引用博客（R14）。

### 三期 —— 业务与运营

| # | 模块 | 文档 | 职责 | 状态 |
|---|---|---|---|---|
| 10 | Profile 个人中心 | [13](modules/13-profile.md) ⬜ | **功能量最大**，留足设计余量 | 未开始 |
| 11 | Product 产品 | [14](modules/14-product.md) ⬜ | 产品展示 + 回测报告 | 未开始 |
| 12 | License 授权 | [03](modules/03-license.md) ✅ | 授权码、宽限期、产品维度、驱动体系 | 未开始 |
| 13 | Client 接入层 | [04](modules/04-client.md) ✅ | EA 接入：**初始化时校验**、防重放、幂等。**暂不实现，后续单独规划** | 未开始 |
| 14 | Media 自媒体 | [15](modules/15-media.md) ⬜ | 公众号 + 小程序，**插件化** | 未开始 |
| 15 | Plugin 插件机制 | [16](modules/16-plugin.md) ⬜ | 自媒体依赖它，可能被其他模块复用 | 未开始 |
| 16 | Stats 站点状态 | [17](modules/17-stats.md) ⬜ | 健康度、每日新增、访客分析 | 未开始 |
| 17 | Audit 审计 | [05](modules/05-audit.md) ✅ | 全量审计日志 | 未开始 |

**文档列的图例**：✅ = 已写完整 · ⬜ = 占位骨架（`docs/modules/_TEMPLATE.md` 是空白模板）。
**轮到实现某个模块前，先把它的文档补完整，再写代码。**

### 后期 —— 平台系统（不属于任何业务模块，先占位）

**积分 · 充值 · 聊天** —— 见 requirements.md 的 R37~R40。这三个是独立的横切系统，不塞进任何模块。

### 依赖方向

```
                                     ┌─→ Support（不依赖任何人）
Content  Blog  Forum  Product  ──────┤
Profile  Stats  Member  Menu         └─→ RBAC（一套表，多态挂 users / admins）

所有模块 ──→ Crypto（横切：加解密）
Client   ──→ License
Media    ──→ Plugin
```

**Support 与 RBAC 不依赖任何模块**；Client 是 License 的传输/安全外壳，License 是业务规则本体，
两者分开是为了以后换接入方式（比如加 WebSocket 心跳）时不动业务规则。

---

## 架构定位：我们用的是「MC」，不是传统 MVC

Laravel 默认骨架是 MVC，但 **V（视图）那一层在纯 API 后端里不存在**：

- Blade 视图已删除，`resources/views/` 只剩一个空目录
- 没有任何路由返回 `view()`，响应一律由 Controller 返回 JSON
- 渲染职责整个搬到了前端（`face` / `admin`）

所以准确说法是 **MC**：**M**odel（数据）+ **C**ontroller（请求入口），V 由前端 + JSON 承担。

### 只靠 MVC 撑不住业务

MVC 的经典失败模式是 **Fat Controller**（业务规则全堆在控制器里）。我们明确分层：

```
请求
 └─ 路由 / 中间件           守卫（public | admin | client）、签名、nonce、限流
     └─ Controller          只做 HTTP 翻译：取参 → 校验 → 调服务 → 出响应
         └─ Service/Driver  业务规则本体（宽限期、产品维度解析…）
             └─ Model       Eloquent，只管数据读写
     Resource               出参整形
```

三条硬规则：

1. **业务规则绝不写进 Model**。Laravel 的 Model 是 Active Record，不是领域模型；宽限期、驱动解析这类规则属于 Service / Driver。
2. **Controller 里不出现 SQL、不出现业务分支**，只有参数校验和调用。
3. **出参一律经 Resource 整形**，不直接吐模型（避免字段泄漏）。

### 运行时：现在用 PHP-FPM 模型，够了

`php artisan serve` 是"每请求重建框架"的经典模型。更快的是 **Laravel Octane**
（应用常驻内存，请求复用已启动的框架，吞吐能提升数倍），但**现在不该上**：

- 瓶颈不在这里：EA 是 **60~300 秒一次的低频心跳**，不是 QPS 密集。
  性能真正的抓手是 **Redis 缓存命中**和**别在关键路径上写库**
- Octane 会引入**状态泄漏**这一整类新坑（静态变量、单例、配置缓存），
  业务还没写时就上，等于自找麻烦

**但代码要提前留路**，遵守三条：

- 请求级数据**不要**放进单例或静态属性（当前用户、当前请求上下文一律随参数传递）
- 跨请求的状态全部放 **Redis**，不放进程内存
- 不在 `config()` 之后改配置

做到这三条，将来切 Octane 只是换部署方式，不是改代码。

---

## 每份模块文档的固定结构

保持统一，查起来才快：

1. **状态** —— 已实现 / 部分实现 / 未开始，以及未完成的部分
2. **职责** —— 做什么、**不做什么**（边界最容易搞混）
3. **对外接口** —— 方法 + 路径 + 请求 + 响应 + 错误码
4. **关键类与函数** —— 文件路径、类名、方法签名、一句话说明
5. **数据表** —— 表名、关键字段
6. **配置与环境变量**
7. **待办**

---

## 本地起服务

```bash
cd core
php artisan serve                 # http://127.0.0.1:8000

# 一期是**零外部服务**：一个 SQLite 文件 + PHP 就够了
php artisan migrate               # 数据库 = database/database.sqlite

# 什么都不用另外起。缓存走 SQLite 的 cache 表（CACHE_STORE=database），
# 所以 optimize:clear / cache:clear 不再依赖 Redis 进程。
# 后期要上 Redis / MySQL，见下面「存储栈」一节 —— 接口都留着，只改 .env。
```

## 环境变量要点

见 `core/.env`，几个容易踩的：

| 变量 | 值 | 为什么 |
|---|---|---|
| `CACHE_STORE` | `database` | 一期缓存走 SQLite 的 `cache` 表，零外部依赖。**登录限流、授权状态缓存都靠它** |
| `QUEUE_CONNECTION` | `database` | 队列也走 SQLite 的 `jobs` 表（见下方「存储栈」的 worker 坑） |
| `CACHE_PREFIX` | `nmnx` | 将来共用 Redis 时做隔离（现在没生效，先留着） |
| `SESSION_DRIVER` | `array` | 纯 API 用令牌，不落库 |
| `CORS_ALLOWED_ORIGINS` | 前端域名列表 | 对应 `config/cors.php`，上线要改 |
| `APP_LOCALE` | `zh_CN` | 默认中文；回退 `en` 以便日后国际化 |

## 存储栈（一期 vs 后期，已定案 2026-10-03）

**一期：SQLite 全栈，零外部服务。** 数据库、缓存、队列全部落在同一个 SQLite 文件里。

| 层 | 一期 | 后期迁移目标 | 切换成本 |
|---|---|---|---|
| 数据库 | SQLite（`database/database.sqlite`） | MySQL | 改 `.env` 的 `DB_*` + `php artisan migrate`，**代码不动** |
| 缓存 | database 驱动（`cache` 表） | Redis | 改 `.env` 的 `CACHE_STORE=redis`，**代码不动** |
| 队列 | database 驱动（`jobs` 表） | Redis / 保留 database 均可 | 改 `QUEUE_CONNECTION` |
| 会话 | array（纯 API 不需要） | — | — |

**为什么一期不用 Redis / MySQL**（用户已拍板）：
1. MySQL 在本机装不上、太卡 —— 先 SQLite，MySQL 连接在 `config/database.php` 里**原样保留**。
2. Redis 加上后开发/测试明显变慢，且 Redis 是普通进程、不随休眠存活，
   一挂就拖垮 `optimize:clear` / `cache:clear`（之前卡 2 秒超时）。切到 database 后实测 110ms。
3. 体量轻，SQLite 完全够；写密集的 **EA 心跳 + 审计日志** 还没写，届时再评估切换。

**迁移的硬前提（现在就要守住，否则后期换不干净）**：
- 所有缓存访问走 Laravel 的 `Cache` 门面，**不直接 `Redis::`**
- 所有查询走 Eloquent / Query Builder，**不写 `DB::raw` 依赖特定方言**
- 表迁移用标准 `Schema` API（`->change()` 在 SQLite 上会重建表，写迁移时心里有数）
- 表名/字段名不用 MySQL 保留字

**queue worker 坑（现在的隐患）**：`QUEUE_CONNECTION=database` 意味着 `jobs` 表是真的会用的，
但**现在没有跑 `queue:work`**。当前没有任何代码 `dispatch()`，所以表是空的、没症状；
一旦以后写了异步任务而不起 worker，任务会静静堆积、不报错。到时候：起 worker，或把队列也切 Redis。

## 三个改动前必读的坑

1. **`bootstrap/app.php` 里的 `redirectGuestsTo(fn () => null)` 不能删。**
   Laravel 13 默认是 `redirectGuestsTo(fn () => route('login'))`，我们没有 login 路由，
   未认证时会在中间件里直接抛 `RouteNotFoundException` 变成 500，
   而且这个异常发生在进异常处理器之前，`shouldRenderJsonWhen` 拦不住。

2. **`resources/views/` 目录不能整个删掉。**
   目录缺失会导致 `php artisan view:cache` / `optimize` 抛 `DirectoryNotFoundException`。
   现在用一个 `.gitkeep` 钉住空目录。

3. **守卫不能写成 `auth:sanctum`，必须写成有 provider 的 `auth:admin` / `auth:member`。**
   Sanctum 的 Guard 用 `hasValidProvider()` 校验主体是不是该 provider 的模型
   （`vendor/laravel/sanctum/src/Guard.php:145-153`），
   但 **provider 为 `null` 时它直接放行**（`:147-149`），而 Sanctum 自动注册的
   `sanctum` 守卫正是 `provider = null`。
   后果是**会员令牌能通过后台守卫**（越权）。回归测试：`tests/Feature/Auth/GuardIsolationTest.php`。
