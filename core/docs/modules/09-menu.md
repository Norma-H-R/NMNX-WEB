# 模块 09 · 菜单管理（Menu）

> 文档版本：`0.1.0` · 最后更新：`2026-10-03`

## 状态

**未开始**。

## 职责

- 前台**菜单与按钮**的可配置化：后台能增、删、改（R3 / R11）
- 支持层级（父子）、排序、显示 / 隐藏、跳转目标
- 给官网首页提供"当前生效的菜单"（R2 首页要读）

**不负责**：

- 菜单的**渲染**（那是 `face/` 前端的事，本模块只出数据）
- 按角色隐藏菜单 —— **第一版不做**。后期如果要做，接 Rbac 的权限名即可（结构上留好字段）

## 对外接口

| 方法 | 路径 | 鉴权 | 说明 | 状态 |
|---|---|---|---|---|
| GET | `/api/v1/public/menu` | 无 | 当前生效的菜单树（首页要读，**必须公开**，否则 SSR 拿不到） | ⛔ 待做 |
| GET | `/api/v1/admin/menus` | 令牌 | 管理端列表（含隐藏项） | ⛔ 待做 |
| POST | `/api/v1/admin/menus` | 令牌 | 新增 | ⛔ 待做 |
| PUT | `/api/v1/admin/menus/{id}` | 令牌 | 修改 | ⛔ 待做 |
| DELETE | `/api/v1/admin/menus/{id}` | 令牌 | 删除 | ⛔ 待做 |

> `GET /public/menu` 必须公开：官网是 SSR，爬虫和首屏渲染都要能拿到菜单结构。
> 这也是 R34「前端请求也要鉴权」不适用于公开内容的一个具体例子。

## 关键类与函数

**规划中**：

| 计划位置 | 用途 |
|---|---|
| `app/Modules/Menu/Models/Menu.php` | 模型，含自关联 `parent()` / `children()` |
| `app/Modules/Menu/Services/MenuTree.php` | 取菜单树 + 缓存 + 失效 |
| `app/Modules/Menu/Http/Controllers/PublicMenuController.php` | 公开读 |
| `app/Modules/Menu/Http/Controllers/AdminMenuController.php` | 后台增删改 |

**缓存**：菜单读多写少，**整个菜单树缓存成一个键**，后台一改就失效（同步删 Redis）。
不要每个菜单项一次查询。

## 数据表

| 字段 | 说明 |
|---|---|
| `id` | |
| `parent_id` | 上级（自关联），`null` 为顶级 |
| `type` | `link`（跳转链接）/ `group`（分组，不可点）/ `button`（页面按钮） |
| `label` | 显示文字 |
| `url` | 跳转目标（`type=link` 时用） |
| `icon` | 图标标识（前端解释） |
| `sort` | 排序，小的靠前 |
| `visible` | 是否显示（后台可先建好隐藏着） |
| `permission_name` | **预留**：将来按权限隐藏菜单项用，第一版留空 |
| `created_at` / `updated_at` | |

## 配置与环境变量

| 配置 | 说明 |
|---|---|
| 缓存键 | `nmnx:menu:tree`（单键，整棵树） |
| TTL | 可长（比如 1 小时），但**写操作必须立即失效**，否则后台改了前台不生效 |

## 待办

- [ ] 定 `type` 的取值集合（link / group / button 够不够）
- [ ] 迁移 + 模型 + 自关联
- [ ] 菜单树缓存与失效（先只做 Redis，CDN 那步等上了 CDN 再接）
- [ ] 公开读接口（菜单树）
- [ ] 后台增删改 + 排序调整接口
- [ ] 与前端约定菜单数据的 JSON 结构（**定死，别让前端猜**）
- [ ] 种子数据：先把现在官网写死的菜单导进去，保证改造后首页不变
