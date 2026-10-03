# 南门拈星 · 网站

> **版本 `0.21.0`** · 最后更新 `2026-10-04`
> 详细变更逐条见 [`core/docs/CHANGELOG.md`](core/docs/CHANGELOG.md)

## 这是什么

南门拈星的门户 + 后台 + 纯 API 后端，三块在一个仓库里：

| 目录 | 是什么 | 技术栈 | 本地地址 |
|---|---|---|---|
| `core/` | **纯 API 后端**（唯一的服务端） | PHP 8.4 / Laravel 13 | `127.0.0.1:8000` |
| `admin/` | 后台管理端 | Vue 3 + TS + naive-ui | `localhost:3100`（**端口固定，CORS 白名单只放 3000/3100**） |
| `face/` | 官网 / 前台 | Nuxt（Vue 3） | `localhost:3000` |

## 怎么跑起来

```bash
# 1) 后端（必须起，另两个都要连它）
cd core
php artisan serve              # → http://127.0.0.1:8000

# 2) 后台
cd admin && npm run dev        # → http://localhost:3100   账号 root / 11111

# 3) 官网
cd face && npm run dev         # → http://localhost:3000   会员 11111 / 11111
```

**数据库**：本地 MySQL 8.4（服务名 `MySQL84`，`127.0.0.1:3306`，`root` **无密码**），
库名 `NMNX-web-Server`。⚠️ 库名带连字符，手写 SQL 要加反引号。

## 文档在哪（**先看这里**）

| 文档 | 作用 |
|---|---|
| [`core/docs/standards.md`](core/docs/standards.md) | **最高约束**：四条铁律 + 代码规范 + 文档规范。动手前必读 |
| [`core/docs/README.md`](core/docs/README.md) | 文档地图（哪份文档放哪儿） |
| [`core/docs/database.md`](core/docs/database.md) | **数据库字段字典**：15 张表逐字段说明 + 维护约定 |
| [`core/docs/permissions.md`](core/docs/permissions.md) | **权限点总表**（121 个 / 14 组）——由 `php artisan rbac:doc` 生成，勿手改 |
| [`core/docs/permissions-model.md`](core/docs/permissions-model.md) | 权限**落地模型**与推进计划（三层判定 + 提权防护 + 模块清单） |
| [`core/docs/CHANGELOG.md`](core/docs/CHANGELOG.md) | 逐步变更记录（每次动手都要写） |
| `core/docs/modules/*.md` | 18 个模块各自的规格与状态 |

## 四条铁律（违反任何一条都不算完成）

1. **每次操作必须留痕**：动代码就同时更新 CHANGELOG 与模块文档；
2. **先写文档再写代码**；
3. **每个函数/方法都要有完整注释块**；
4. **一个模块端到端一次做完**（模块代码 → 落库 → API → **对接后台并删掉 mock** → 测试与文档），
   「后端好了前端还吃 mock」「改了样式没在浏览器里看过」都算没做完。

---

## 本次（`0.21.0`，2026-10-04）做了什么

这一轮的主线是**把"权限"从"能看能管"做到"真的拦得住"**，外加数据库迁到 MySQL、以及博客模块起步。

### 1. 权限（模块 08 Rbac）—— 端到端闭环

- **121 个权限点 / 14 组 / 5 个角色**全部落库（前 98 个逐字来自 admin 前端契约，另 23 个是逐项对照 18 个模块补齐的缺口：
  反馈 / 订单与支付 / 统计 / 插件 / 密钥 / 审计拆两类）。
- **预加载层**：权限目录与角色基线走「请求内记忆 + 跨请求缓存」两层，
  鉴权不再每个请求 join 三张表（后台改完权限，下一个请求即生效）。
- **8 条读写接口**（权限目录 + 角色 CRUD），全部带校验与「改 key 不需要同步任何引用」的设计
  （因为 pivot 存的是权限点的数字 ID）。
- **鉴权通道**（本轮重点）：`permission:<key>` 中间件 —— **全项目唯一的权限判定入口**。
  - 路由上声明：`->middleware('permission:forum.post.delete')`；
  - 未登录 401、没权限 **403 且带具体提示**（"你没有「删除帖子」权限"）；
  - 管理员通过 `admins.user_id` 挂回用户总表，**权限只有一个主体**，判定不用分支。
- **提权防护**（用户专门问过）：服务端**从不读**客户端声明的权限。
  「改前端列表」「请求体里塞 role/permissions」都提不了权 —— **有回归测试钉住**。
- **前端**：登录响应 + `/me/permissions` 下发"我自己那份"权限；
  403 统一弹窗 + **收到 403 后自动重拉权限**（避免"按钮还在但一点就炸"）。

### 2. 数据库迁到 MySQL + 备注补全

- SQLite → **MySQL 8.4**（`NMNX-web-Server`），10 条迁移全过、种子重灌，数据完好。
  SQLite 那份**留作备份没删**。
- **列备注 109/109（100%）**：迁到 MySQL 后能逐列统计覆盖率，才发现并补齐了 19 个漏掉的列
  （其中 `users.role_id` 是因为 `->comment()` 写在了 `constrained()` **之后**，
  会挂到外键定义上而不是列上 —— 这个坑写进了迁移注释与字段字典）。
- 顺手修了 **3 条"在说假话"的过时备注**（`users.status` 还写着两值、`users.phone` 说"预留给短信登录"、
  `cache.key` 写死了一个早就升过版本的缓存键）。
- 清理：删掉遗留的空壳库 `nmnx`（删前逐表证明它是空的；`information_schema`/`mysql` 等系统库做了硬保护）。

### 3. 博客（模块 10）—— 数据层起步

- 先扫清两端现状：**官网博客已完全设计好**（`face/app/composables/useBlog.ts` 就是契约），
  **后台只有占位壳**（`BlogView.vue` 9 行），10 个 `blog.*` 权限点一个都没被用过。
- 按 `docs/modules/10-blog.md` 建 **四张表**：`blogs` / `blog_attachments` / `reactions`（通用互动）/
  `comments`（**通用评论，论坛将来直接复用，表结构一行不动**）。
- 灌入**模拟数据**：作者 5 位、博客 12 篇（含草稿）、评论 10 条，外加附件与互动
  （全部写死不用随机数，幂等可重跑）。
- ⚠️ **接口与前端尚未接** —— 官网 `/blog` 现在读的仍是前端假数据。

### 4. 文档体系

- 新增 `/**/permissions.md`（命令生成的总表）、`permissions-model.md`（落地模型 + 推进计划 + 提权防护表）、
  `database.md`（字段字典）。
- `standards.md` 从「三条铁律」扩到「**四条**」，新增"一个模块端到端一次做完"，
  并把"哪些状态算没做完"（真实踩过的四种）写进去。

### 5. 修掉的故障（都是真实发生、有堆栈的）

- **接口返回的不是 JSON，而是 PHP 弃用警告的 HTML**（HTTP 200 却是 HTML，前端必炸）→
  `public/index.php` 里 `display_errors=0` 兜底（只关显示不关记录）。
- **`/` 根地址 500**，报 `mysqli_num_rows() expects 1 argument, 6 given` ——
  实际是**服务器进程内部函数表错位**（调 `openssl_encrypt` 被派发给了 `mysqli_num_rows`）。
  已证实 PHP 本身没问题（新进程里 openssl 正常）。顺手把 `EncryptCookies` 从 web 组拿掉
  （纯 API 用 Bearer 令牌，本来不该加密 Cookie）。
- **令牌失效时前端只静静显示空列表**（像"数据没了"）→ 401 自动回登录页；
  403 弹窗 + 重拉权限。

## 下一步（按依赖顺序）

1. **博客接口层**：公开读（列表/详情/相关）+ 会员写（增删改/发布/互动）+ 通用评论；
2. **博客后台管理**：把 `BlogView.vue` 占位壳换成真的内容管理，
   并按 `blog.review` / `blog.publish` 挂上 `permission:` ——
   **这会是第一个真实挂权限的模块**，把"建表→接口→鉴权通道→前端显隐→403弹窗"整条链端到端跑通；
3. `08-rbac` 收尾：两个 `mock/*.ts` 兼容层待删；会员侧权限下发；
4. 其余模块按 `permissions-model.md` 第 4 节的顺序推进。
