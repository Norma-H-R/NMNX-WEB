# 模块 08 · 权限（Rbac）

> 文档版本：`0.2.0` · 最后更新：`2026-10-03`

## 状态

**已完成（按铁律 4 的五步走完）**：

| 步骤 | 状态 |
|---|---|
| ① 模块代码 | ✅ 3 个模型 / `RoleTone` / `PermissionRegistry`（预加载）/ `RbacService`（写规则） |
| ② 落库 | ✅ 四张表 + 121 个权限点 + 5 个角色；**字段备注 100% 覆盖**（MySQL 实测 109/109 列） |
| ③ API | ✅ 8 条路由（权限 4 + 角色 4），全部在 `auth:admin` 里 |
| ④ 对接后台 | ✅ 权限点管理 + **角色管理**都改成从接口读；`mock/permissions.ts`、`mock/roles.ts` 已成兼容层，**本地不再存权限/角色数据** |
| ⑤ 验证与文档 | ✅ 19 条测试（接口 10 + 注册表 9）；本文档 + `docs/database.md` + CHANGELOG |

### 接口清单

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/v1/admin/permissions` | 目录（14 组 / 121 条，每条带 `id`）+ 全部 key |
| POST | `/api/v1/admin/permissions` | 新增（校验标识格式 / 重复 / 分组是否存在） |
| PATCH | `/api/v1/admin/permissions/{id}` | 改 label/说明/**key**（改 key 不需要同步任何引用） |
| DELETE | `/api/v1/admin/permissions/{id}` | 删（外键级联清引用） |
| GET | `/api/v1/admin/roles` | 角色 + 各自基线 + `users_count` + 配色选项 |
| POST | `/api/v1/admin/roles` | 新增（**key 由后端生成** `custom-1`…） |
| PATCH | `/api/v1/admin/roles/{id}` | 改名字 / 说明 / 配色 / 基线（**全量替换**；owner 会被拒） |
| DELETE | `/api/v1/admin/roles/{id}` | 删（**先把人转成 member** 再删，返回 `moved_users`） |

⚠️ **路由参数一律用数字 `id`，不用 `key`** —— key 允许改名，一改 URL 就变了。

## 职责

- 定义「谁能做什么」：**权限点**（能力名）、**角色**（身份 + 默认权限基线）、**个人增减**
- 给其它模块提供判定入口（`can('forum.post.delete')`）
- 给后台提供权限目录与角色的增删改查

**不负责**：

- 认证（你是谁）—— 那是模块 01 Auth / 07 Member
- 具体业务规则（比如"删帖子前要先清空回复"）

## 数据模型（四张表）

| 表 | 作用 | 关键字段 |
|---|---|---|
| `permissions` | **权限目录**（可增删改的数据，不是枚举） | `key`(唯一) `label` `description` `group_title` `group_sort` `sort` `is_builtin` |
| `roles` | **角色 / 身份** | `key` `name` `description` `tone` `is_builtin` `is_locked` `grants_all` `sort` |
| `role_permissions` | 角色的**默认权限基线** | `role_id` `permission_id` |
| `user_permissions` | **个人增减**（基线之外） | `user_id` `permission_id` `granted` |

用户总表加了三列：`role_id`（单角色）、`public_id`（`NMX-U-000017`）、`last_seen_at`。

### 三个容易混的布尔字段

| 字段 | 含义 | 为什么单独存在 |
|---|---|---|
| `is_builtin` | 种子导入的（相对界面自定义的） | 只影响界面打不打"自定义"标；Seeder 只同步内置项 |
| `is_locked` | **不允许删除** | `owner` / `member` 是系统依赖：删了 owner 没人能管权限，删了 member 新用户没身份可挂 |
| `grants_all` | **隐含全部权限** | `owner` 用它。存快照的话，以后新增权限点 owner 就没有了 —— 前端特意这么设计，照抄 |

### 两个存储决定（别改回去）

1. **pivot 用数字 ID 做外键，不存权限的 key。**
   权限点**允许改名**（前端就有"改名并同步所有引用"的逻辑）。存 key 的话，改一次名要全库同步一遍，
   漏一处就成了指向不存在权限的"幽灵项"——界面上看不出问题，鉴权时永远判不过。
   存 ID 则改名天然安全。前端用 key 是因为它没有外键，我们不必跟着受这个罪。
2. **`description` 不叫 `desc`**（`DESC` 是 SQL 保留字）；`key` 保留了（和前端契约一致），
   但它是 MySQL 保留字，**手写 SQL 要加引号**（查询构造器会自动加，平时无感）。

## 权限目录（14 组 / 121 个）

前 9 组、共 98 个**逐字来自** `admin/src/mock/permissions.ts`（前后端的契约草稿）；
其余 23 个是逐项对照 18 个模块后**补齐的缺口**。

| 分组 | 个数 | 来源 |
|---|---|---|
| 用户管理 | 13 | 契约 |
| 博客（用户投稿） | 10 | 契约 |
| 文章（官方发布） | 10 | 契约 |
| 公告 | 7 | 契约 |
| **反馈** | **6** | **补齐**（模块 12 原本一个权限点都没有） |
| 论坛 | 16 | 契约 |
| 自媒体 | 9 | 契约 |
| 产品与授权 | 19 | 契约 |
| **订单与支付** | **4** | **补齐**（"在登录上购买授权"→ 会有订单） |
| 审计 | 7 | 契约 5 + **补 2**（拆出"操作日志"/"访问日志"） |
| **统计** | **3** | **补齐**（模块 17；访客量、页面停留时长） |
| **插件** | **5** | **补齐**（模块 16） |
| **密钥** | **3** | **补齐**（模块 06；密钥轮换是最高危操作，不能混进 `system.danger`） |
| 系统 | 9 | 契约 |
| **合计** | **121** | |

**还缺一处待你确认**：`09 菜单` 模块没有权限点。如果它是运营可配的导航/菜单，需要 `menu.*`；
如果只是代码里的路由表，就不需要。

## 角色（5 个内置 + 可自定义）

| key | 名称 | 配色 | 基线 | 约束 |
|---|---|---|---|---|
| `owner` | 超级管理员 | gold | **隐含全部**（不存快照） | 不可删 |
| `admin` | 管理员 | cyan | 19 个 | — |
| `moderator` | 论坛版主 | violet | 16 个 | — |
| `blogger` | 博客博主 | green | 4 个 | — |
| `member` | 普通用户 | slate | 0 个 | **不可删**（默认身份） |

**一个用户一个角色**（契约里 `role` 是单数），个人差异走 `user_permissions` ——
不是"挂多个角色"。这样角色数量不会爆炸。

## 预加载（这一层的重点）

鉴权**每个请求都要读**权限，直接查库要 join 三张表。所以 `PermissionRegistry` 做了两层：

```
① 请求内记忆（实例属性）     一次请求最多 load 一次
② 跨请求缓存（Cache 门面）   命中时只是一次 cache 读，不碰业务表
```

- 容器里注册成**单例**（`RbacServiceProvider`）—— 不是单例的话，记忆等于没有。
- ⚠️ **任何写操作之后必须 `flush()`**（`RbacService` 的写方法与 `RbacSeeder` 内部都会调）。
- 缓存有 1 天 TTL 兜底，防"有人绕过服务直接改表"后永远读旧数据。
- 代价：**绕过服务直接改表不会立刻生效**，直到 flush。这条有专门的测试
  （`RbacRegistryTest::test_registry_serves_cache_until_flushed`）当文档。

## 关键类

| 位置 | 类 / 方法 | 说明 |
|---|---|---|
| `App\Modules\Rbac\Services\PermissionRegistry` | `catalog()` / `roles()` / `allKeys()` / `permissionsOfRole()` / `effectiveFor()` / `flush()` | 预加载层 + 权限计算 |
| `App\Modules\Rbac\Models\{Permission,Role,UserPermission}` | — | 三个模型（role_permissions 是纯关联表，无模型） |
| `App\Modules\Rbac\Enums\RoleTone` | `options()` | 角色配色（**只存色号，色值在前端**） |
| `App\Modules\Rbac\RbacServiceProvider` | `register()` | 注册注册表单例 |
| `Database\Seeders\RbacSeeder` | `run()` | 121 个权限点 + 5 个角色 |

`effectiveFor(User)` 的算法：**角色基线 ± 个人增减**，`granted=true` 加进来、`granted=false` 收回。

## Seeder 的幂等规则

| 对象 | 重跑 Seeder 时 |
|---|---|
| 内置权限点 | 按代码同步 label / 说明 / 分组 / 排序（**代码是权威**） |
| 界面新增的权限点（`is_builtin=false`） | **完全不碰** |
| 内置角色的权限基线 | **只在新建时写入**，之后不覆盖（避免冲掉后台的调整） |
| 内置角色的名字 / 说明 / 配色 | 同步 |

跑完会 `flush()` 预加载缓存。整个 seed 包在一个事务里，中途失败不会留下"半套目录"。

> 性能：逐条 `updateOrCreate` 写 121 条在 SQLite 上要 **21.6 秒**；
> 改成一次性 `upsert` 后 **343 毫秒**（快 63 倍）。别改回逐条。

## 与前端契约的对齐情况

| 契约（`admin/src/types/user.ts`） | 本项目 | 状态 |
|---|---|---|
| `role`（单角色） | `users.role_id` | ✅ 已对齐 |
| `publicId`（`NMX-U-000017`） | `users.public_id` | ✅ 已对齐（模型钩子自动分配 + 存量回填） |
| `status`: `active/muted/banned` | `MemberStatus` 三值 | ✅ 已对齐（补齐了"禁言"；禁言**仍可登录**） |
| `lastSeenAt` | `users.last_seen_at` | ⚠️ 列已建、登录时写入；**每请求更新留给审计模块**（SQLite 单写入者，不能每请求写） |
| `nickname` | `users.name` | ⏳ **未对齐，待你定**：是后端改叫 `nickname`，还是前端契约改叫 `name` |
| `role.permissions`（基线） | `role_permissions` + 预加载 | ✅ 已对齐 |

## 测试

`tests/Feature/Rbac/RbacRegistryTest.php`（9 条）：目录条目数与契约 key、owner 隐含全部且不存快照、
5 个角色基线的数量、个人增减的加/收、无角色用户不崩、预加载"改表不生效直到 flush"、
单例绑定、`public_id` 自动分配、**角色被占用时删不掉**（数据库外键兜底，不靠调用方自觉）。

`tests/Feature/Member/MemberAuthTest.php` 另加：注册自动挂 `member` 角色、禁言仍可登录、封禁不可登录。

## 待办

- [x] ~~**接口层**：权限目录、角色 CRUD~~（2026-10-03 完成，8 条路由见上）
- [x] ~~**前端对接**：`mock/permissions.ts`、`mock/roles.ts` 改为从接口读~~（2026-10-03 完成，两个文件都成了兼容层）
- [ ] **删掉两个兼容层**：把剩下几处 `@/mock/{permissions,roles}` 的 import 迁到 `@/api/`，然后删文件
- [ ] **鉴权中间件 + `can()`**：把 `effectiveFor()` 接进请求生命周期，并**按用户缓存**这份结果。
      ⚠️ 这是本模块**唯一还没做**的核心件 —— 现在权限模型能用、能管，但**还没有任何接口真的去拦**
- [ ] 后台的公共基础数据（角色 / 权限目录）目前各页面自己 `loadXxx()`；
      后续做个统一的"登录后预加载"，免得漏拉导致页面显示空名
- [ ] `09 菜单` 是否需要 `menu.*` 权限点（待确认）
- [ ] `nickname` 字段名对齐（见上表）
- [ ] 存量数据的 `public_id` 回填命令（生产环境用；本地已由迁移处理）
