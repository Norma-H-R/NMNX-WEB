# 数据库字段字典

> 文档版本：`1.0.0` · 最后更新：`2026-10-03`

## 先读这一节：现在跑在什么库上

**开发库已经是 MySQL 8.4**（2026-10-03 从 SQLite 迁过来的）：

| 项 | 值 |
|---|---|
| 连接 | `mysql`，`127.0.0.1:3306`，`root` **无密码** |
| 库名 | **`NMNX-web-Server`** ⚠️ 带大写与连字符，手写 SQL 必须反引号 |
| Windows 服务 | `MySQL84`（安装于 `E:\dev\mysql`，仅监听 127.0.0.1） |
| 迁移前的 SQLite | `core/database/database.sqlite`（**保留着当备份**，没删） |

**测试不受影响**：`phpunit.xml` 钉死 `DB_CONNECTION=sqlite` + `DB_DATABASE=:memory:`，
所以 `php artisan test` **跑在内存库上**，永远不会碰开发库（也快）。

### 列备注：现在是真备注

| 引擎 | `->comment()` 会怎样 |
|---|---|
| **MySQL（当前）** | ✅ **真的写进 `COLUMN_COMMENT`** —— DB 工具里直接能看。实测覆盖 **109/109 列** |
| SQLite（旧） | ❌ 静默忽略（SQLite 没有列备注这个概念） |

所以字段说明有**两个地方**，互相补位：迁移里的 `->comment()`（DB 里能看）+ 本文档（读起来方便）。

### ⚠️ 一个踩过的坑：`comment()` 的书写顺序

```php
// ❌ 错的：comment 挂到了**外键定义**上，列上没有备注（不报错，静默丢失）
$table->foreignId('role_id')->constrained('roles')->restrictOnDelete()->comment('所属角色');

// ✅ 对的：comment 写在 constrained() **之前**
$table->foreignId('role_id')->comment('所属角色')->constrained('roles')->restrictOnDelete();
```

原因：`constrained()` / `restrictOnDelete()` 返回的是 `ForeignKeyDefinition`，
不是列定义。这个坑是靠"迁到 MySQL 后逐列统计备注覆盖率"才发现的 ——
`users.role_id` 曾经就是这么丢的。

**约定**：注释里的 `⚠️` 表示**容易踩的坑**，改这块之前先读它。
业务代码**不写方言 SQL**（SQLite 的 `||`、MySQL 的 `CONCAT` 各写一套就完了）。

## 表一览（15 张）

| 分组 | 表 | 干什么 |
|---|---|---|
| **人 / 账号** | `users` | 前台会员**总表**（所有"人"，也是 Rbac 的授权主体） |
| | `admins` | 后台管理员**分表** |
| | `personal_access_tokens` | 令牌（会员与管理员**共用**，靠 `tokenable_type` 区分） |
| | `password_reset_tokens` | 密码重置令牌 |
| | `sessions` | 会话（⚠️ 本项目走 Bearer 令牌，这张表基本不用） |
| **权限** | `permissions` | 权限目录（121 条，可增删改的**数据**不是枚举） |
| | `roles` | 角色 / 身份（5 个内置 + 自定义） |
| | `role_permissions` | 角色的**默认权限基线** |
| | `user_permissions` | **个人增减**（基线之外多给 / 收回） |
| **基础设施** | `cache` / `cache_locks` | 缓存与锁（⚠️ 权限预加载缓存就存在这里） |
| | `jobs` / `job_batches` / `failed_jobs` | 队列（⚠️ 表就位但**还没起 worker**） |
| | `migrations` | 迁移记录（框架自带） |

---

## 人 / 账号

### `users` —— 前台会员总表

> 由 5 条迁移共同构成：`0001_01_01_000000`（骨架）+ `160000` + `170000` + `180100` + `180200`。
> **改字段前先看这 5 个文件的分工**，别只看骨架那条。

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | 自增主键 | ⚠️ **仅内部逻辑用，不要展示给用户** —— 对外用 `public_id` |
| `name` | string | 昵称 / 前台显示名。注册时留空则由邮箱前缀兜底 |
| `email` | string 唯一 | 登录标识之一（与 `phone` 二选一），全表唯一 |
| `email_verified_at` | timestamp 可空 | 邮箱验证时间；`null` = 还没验证过 |
| `password` | string | 密码哈希。⚠️ 模型有 `hashed` cast，赋明文会自动哈希，**别再手动 `Hash::make`** |
| `remember_token` | string 可空 | "记住我"令牌。⚠️ API 场景**恒为空**（走 Bearer 令牌，不用 session） |
| `created_at` | timestamp | 创建时间。前端要的"加入时间"就是它，**不要另加 `joined_at`** |
| `updated_at` | timestamp | 最后更新时间 |
| `phone` | string 唯一 可空 | 手机号。登录标识之一。⚠️ **不做格式校验**（测试期统一填 `11111`） |
| `status` | string(16) | **三值**：`active` 正常 / `muted` **禁言（仍可登录！）** / `banned` 封禁（不能登录）。见 `MemberStatus` |
| `last_login_at` | timestamp 可空 | 最后一次**登录成功**时间 |
| `last_login_ip` | string(45) 可空 | 最后登录 IP。45 位以兼容 IPv6 |
| `tier` | string(16) | 会员等级**代码**（`normal`/`silver`/`gold`）。⚠️ 中文由**前端**映射，库里不存展示文案 |
| `points` | unsigned int | 积分**余额**。⚠️ 只存余额；将来要记流水（谁何时加了多少）是**另一张表** |
| `role_id` | 外键 → `roles` 可空 | 所属角色（**单角色**）。`restrictOnDelete`：角色还有人用就删不掉 |
| `public_id` | string(24) 唯一 可空 | 对外公开 ID，形如 `NMX-U-000002`。由模型 `created` 钩子按自增 id 派生 |
| `last_seen_at` | timestamp 可空 | 最后**活跃**时间。⚠️ **≠ `last_login_at`**：登录是"进门"，活跃是"还在屋里" |

### `admins` —— 后台管理员分表

> 与 `users` 是**两张表、两个模型、两个守卫**（需求 D1）。混用一张表会让"会员令牌访问 admin 接口"变成可能。

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | 自增主键 | 管理员数量少，内部用；对外展示用 `username` / `name` |
| `username` | string(64) 唯一 | 登录账号，前端登录框填的就是它 |
| `name` | string(64) | 显示名，用于界面与审计日志 |
| `email` | string(190) 可空 唯一 | 邮箱，仅用于通知 |
| `password` | string | 密码哈希（casts 的 `hashed` 自动处理） |
| `status` | string(16) | `active` 正常 / `disabled` 禁用（不能登录）。见 `AdminStatus` |
| `remember_token` | string 可空 | ⚠️ API 场景恒为空 |
| `last_login_at` | timestamp 可空 | 最后一次登录成功时间 |
| `last_login_ip` | string(45) 可空 | 最后登录 IP |
| `created_at` / `updated_at` | timestamp | 创建 / 更新 |

### `personal_access_tokens` —— 令牌

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | 自增主键 | — |
| `tokenable_type` | string | 主体模型类名：`App\Models\User` = 会员，`Admin` = 管理员。**守卫隔离靠它** |
| `tokenable_id` | unsigned bigint | 主体 ID |
| `name` | text | 令牌备注（设备名）：`member` / `web` / 后台设备名 |
| `token` | string(64) 唯一 | 令牌的**哈希**（不是明文）。⚠️ 明文只在签发那一刻返回一次 |
| `abilities` | text 可空 | 能力范围。本项目未用细粒度能力，恒为 `*` |
| `last_used_at` | timestamp 可空 | 最后使用时间。可用来揪"不用的令牌" |
| `expires_at` | timestamp 可空 | ⚠️ **`null` = 永不过期**（本项目现状）。4h/24h 只在前端本地生效 |
| `created_at` / `updated_at` | timestamp | 签发 / 更新 |

### `password_reset_tokens`

| 字段 | 说明 |
|---|---|
| `email` | 主键。一个邮箱同时只有一条重置记录 |
| `token` | 重置令牌的**哈希**。明文的只在邮件里出现一次 |
| `created_at` | 发起时间，用来判断是否过期 |

### `sessions`

| 字段 | 说明 |
|---|---|
| `id` | 会话 ID（主键） |
| `user_id` | 登录用户；访客为 `null` |
| `ip_address` | 客户端 IP（45 位） |
| `user_agent` | 浏览器 UA 原文 |
| `payload` | 会话数据（序列化整包） |
| `last_activity` | 最后活跃时间（**Unix 秒**）。过期清理按它判断 |

> ⚠️ 本项目认证走 **Bearer 令牌**（Sanctum），不依赖 session，所以这张表基本是空的。
> 它是框架骨架，留着是因为将来可能要用 session 驱动（比如后台的 Web 会话）。

---

## 权限（模块 08 Rbac）

### `permissions` —— 权限目录

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | 自增主键 | ⚠️ **改 / 删都用它当路由参数，不要用 `key`** |
| `key` | string(64) 唯一 | 能力名，如 `forum.post.delete`。后端中间件里 `can('…')` 直接用它。⚠️ 它是 MySQL 保留字，**手写 SQL 要加引号**（查询构造器会自动加） |
| `label` | string(64) | 显示名，如「删除帖子」 |
| `description` | string(255) | 说明，给管理员看的。⚠️ 叫 `description` 不叫 `desc` —— `DESC` 是 SQL 保留字 |
| `group_title` | string(32) | 分组标题，后台按它分块显示 |
| `group_sort` | unsigned smallint | 分组顺序 |
| `sort` | unsigned smallint | 组内顺序 |
| `is_builtin` | boolean | 种子导入的（`true`）还是界面上新增的（`false`）。⚠️ Seeder 只同步内置项，界面新增的**不碰** |
| `created_at` / `updated_at` | timestamp | — |

### `roles` —— 角色 / 身份

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | 自增主键 | — |
| `key` | string(32) 唯一 | 角色标识：`owner`/`admin`/`moderator`/`blogger`/`member`/`custom-1`… |
| `name` | string(32) | 显示名，如「论坛版主」 |
| `description` | string(255) | 说明 |
| `tone` | string(16) | 徽章**配色代码**（`cyan`/`violet`/`green`/`gold`/`red`/`slate`）。⚠️ 只存色号，**色值在前端** |
| `is_builtin` | boolean | 内置角色（相对自定义） |
| `is_locked` | boolean | ⚠️ **不允许删除**：`owner` / `member` 是系统依赖（删了 owner 没人管权限，删了 member 新用户没身份） |
| `grants_all` | boolean | ⚠️ **隐含全部权限**（`owner`）。为 `true` 时**不存权限快照** —— 否则后续新增权限点，它的快照就过期了 |
| `sort` | unsigned smallint | 显示顺序 |
| `created_at` / `updated_at` | timestamp | — |

### `role_permissions` —— 角色的默认权限基线

| 字段 | 说明 |
|---|---|
| `id` | 自增主键 |
| `role_id` | 外键 → `roles`，**级联删除** |
| `permission_id` | 外键 → `permissions`，**级联删除** |
| `created_at` / `updated_at` | — |

> ⚠️ **存的是权限点的数字 ID，不是 key。** 这不是洁癖：权限点允许改名，
> 存 ID 则改名天然安全（前端原来那套"改名同步所有引用"在我们这里不存在）。
> `(role_id, permission_id)` 有唯一索引，防止同一对关系插两次。
> `owner` 在这张表里**没有记录**（它靠 `grants_all`）。

### `user_permissions` —— 个人权限增减

| 字段 | 说明 |
|---|---|
| `id` | 自增主键 |
| `user_id` | 外键 → `users`，级联删除 |
| `permission_id` | 外键 → `permissions`，级联删除 |
| `granted` | `true` = 在角色基线之外**额外授予**；`false` = 从基线里**收回** |
| `created_at` / `updated_at` | — |

> 主体为什么是 `user_id` 而不是多态的：我们有**用户总表**（管理员 = `users` + `admins` 分表），
> 所以"谁能有权限"只需要指向 `users`，用不上多态。

---

## 基础设施

### `cache` / `cache_locks`

| 表.字段 | 说明 |
|---|---|
| `cache.key` | 缓存键（主键）。⚠️ 权限预加载的缓存就在这里：`nmnx.rbac.registry.v2` |
| `cache.value` | 缓存内容（PHP 序列化整包） |
| `cache.expiration` | 过期时间（**Unix 秒**） |
| `cache_locks.key` | 被锁的键，语义是"这个键正在被算" |
| `cache_locks.owner` | 持锁者随机串。⚠️ 解锁要对得上，防 A 把 B 的锁放了 |
| `cache_locks.expiration` | 锁过期时间。持锁进程崩了靠它自动放锁 |

> ⚠️ 鉴权**每个请求**都要读权限，靠的就是这张表的一行（不是 join 三张业务表）。
> 换 Redis 时只需改 `CACHE_STORE`，代码不用动。

### `jobs` / `job_batches` / `failed_jobs`

| 表.字段 | 说明 |
|---|---|
| `jobs.queue` | 队列名（`default`/`mail`/`exports`…） |
| `jobs.payload` | 任务载荷（序列化） |
| `jobs.attempts` | 已尝试次数，超限丢进 `failed_jobs` |
| `jobs.reserved_at` | 被 worker 取走的时间（Unix 秒）；`null` = 还在等 |
| `jobs.available_at` | 最早可执行时间，延时任务靠它 |
| `job_batches.id` | 批次 ID（UUID，主键） |
| `job_batches.pending_jobs` | 还没跑完的任务数，归零即批次结束 |
| `job_batches.failed_job_ids` | 失败任务 ID 列表 |
| `job_batches.options` | 批次回调配置（then / catch / finally） |
| `failed_jobs.uuid` | 失败记录 UUID，`queue:retry <uuid>` 用 |
| `failed_jobs.exception` | 异常堆栈全文。⚠️ 可能有敏感数据（SQL、路径） |
| `failed_jobs.failed_at` | 失败时间 |

> ⚠️ **队列现在没跑起来**：`QUEUE_CONNECTION=database`、表也就位，但**没有 `queue:work`**，
> 也还没有任何异步任务。第一个异步任务落地时**必须同时起 worker**，
> 否则任务会安静地堆在 `jobs` 里谁也不执行。

### `migrations`

框架自带表，记"哪条迁移跑过了"：`migration`（迁移文件名）、`batch`（批次号，
决定 `migrate:rollback` 一次回滚多少条）。**不要手工改它**。

---

## 怎么查

```bash
# 1) 看表与行数
cd core && php artisan db:show

# 2) 看某张表的列定义
php artisan db:table permissions

# 3) 直接查数据（推荐 tinker，不用担心锁）
php artisan tinker
>>> App\Modules\Rbac\Models\Permission::count()                 // 121
>>> App\Models\User::where('phone','11111')->first()->public_id // NMX-U-000002
>>> Cache::has('nmnx.rbac.registry.v2')                         // true
```

图形界面用 **DB Browser for SQLite** 或 **TablePlus** 直接打开
`core/database/database.sqlite`。

> ⚠️ 后端在跑的时候，**别用外部工具去写**这个文件 —— SQLite 是单写入者，写冲突会报
> `database is locked`。只读查看没问题。

## 维护约定

1. **加字段就写 `->comment()`**，同时在本文件对应表里补一行 —— 两处都要。
2. **不要改已经跑过的迁移文件来加列**：已迁移的库不会重跑它，改了等于没改。加列要新建迁移。
3. `⚠️` 标记的字段，改之前先确认影响面（文档里都写了为什么）。
4. 业务代码**不写方言 SQL**（SQLite 的 `||`、MySQL 的 `CONCAT` 各写一套就完了）——
   需要拼接就在 PHP 里拼，跨引擎都能跑。
