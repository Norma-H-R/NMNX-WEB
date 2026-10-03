# 模块 11 · 论坛（难点）（Forum）

> 文档版本：`0.2.0` · 最后更新：`2026-10-04`

## 状态

**前端已实现（`face/`，跑本地假数据）；后端未开始。**

| 端 | 情况 |
|---|---|
| `face/` | 版块列表 / 帖子列表 / 帖子详情 / 发帖四个页面已实现，数据来自 `app/composables/useForum.ts`（5 版块 + 46 帖）。**评论区直接复用博客那套插件**，只换 `targetType='forum_post'`。 |
| `core/` | `app/Modules/Forum/` 目录骨架在，**数据表、接口、服务都没写**。本文档就是它们的规格。 |

需求编号：**R13**（论坛模块，难点需专门设计）、**R14**（博客与论坛互通）、**R19**（官方对博客、论坛的管理）。

## 职责

**做什么：**

- **版块**：列表、进入某个版看帖、版块信息（帖数 / 回复数 / 最后回复）
- **帖子**：发帖、编辑、删除、置顶（版主/官方）、浏览计数、点赞
- **回复**：走**共用的评论系统**（模块 10 的 `comments` 表），不另起一套
- **搜索**：按标题 / 正文 / 作者
- **通知**：有人回复你的帖子、有人 @ 你 → 通知中心
- **官方管理**（R19）：置顶、锁定、移动版块、删帖、封禁

**不负责：**

- **博客**（长文、会员 UGC 的另一半）→ 模块 10 Blog
- **官方文章 / 动态 / 公告** → 模块 02 Content
- **评论本身** → 独立系统，见下面「依赖」
- **上传** → 见模块 10 的「附件与媒体」，两边共用同一个上传接口

## 依赖

| 依赖 | 说明 |
|---|---|
| **评论系统**（10-blog.md 决策 9） | 论坛的回复**不是**自己的一套表。`comments` 表用 `target_type='forum_post'` 挂在帖子上，**和博客共用同一张表、同一套接口**。前端也是同一个 `CommentThread.vue` |
| **互动**（同上的 `reactions` 表） | 帖子的点赞、评论的赞/踩/收藏，都写这张表，用 `target_type` 区分 |
| Support / Member / Content | 统一响应体、当前会员、缓存失效 |

## 关键架构决策

1. **论坛与博客的缓存策略**相反**（这条在 [02-content.md](02-content.md) 里已经定过，照做）：**
   - 论坛**高频写入** → **详情页不做静态预渲染**，按帖 / 按版块失效
   - **严禁**"每次回复触发全站缓存失效"
   - 列表用短 TTL（30s~2min）兜底

   > ⚠️ **前端现状不符**：`/forum/{slug}` 现在跟着 `nuxt generate` 一起被预渲染了（因为它挂在列表页的 `<a href>` 上）。真实上线前要把它排除在预渲染之外。

2. **帖子和博客是两套表、两套接口。** 唯一共用的是「评论」和「上传」这两块能力。
   博客的 `slug` 发布后锁定；**论坛帖的 `slug` 可以跟着标题变**（帖子是活的，链接断了也无所谓）。

3. **楼层 vs 树形**：帖子本身是**平的**（一个帖子就是一串回复），层级全部交给评论系统的 `parent_id` + `path` 去表达 —— 所以论坛**不需要**自己的"楼层号"字段。

4. **版块的权限模型先留空**：`boards` 上有 `min_role`（默认 `user`）和 `postable`（默认 true）。
   公告版块将来要设成"只有管理员能发"，但**这一期不做权限判定**，只把字段留着。

## 对外接口

> 响应体统一由**模块 00 Support** 定义。状态：✅ 已完成 / 🟡 部分 / ⛔ 待做

### 公开读（`/api/v1/public/forum`）

| 方法 | 路径 | 鉴权 | 说明 | 状态 |
|---|---|---|---|---|
| GET | `/boards` | 无 | 版块列表（带帖数 / 回复数 / 最后回复） | ⛔ 待做 |
| GET | `/posts` | 无 | 帖子列表：`?board=…&sort=latest\|hot&q=…&page=…` | ⛔ 待做 |
| GET | `/posts/{slug}` | 无（可选带 token 以回显 `mine`） | 帖子详情 | ⛔ 待做 |
| GET | `/stats` | 无 | 首页那行统计（帖子 / 回复 / 版块 / 今日新增） | ⛔ 待做 |

### 会员写（`/api/v1/member/forum`）—— `auth:member`

| 方法 | 路径 | 说明 | 状态 |
|---|---|---|---|
| POST | `/posts` | 发帖：`{ board_id, title, body_md }`，可存草稿 | ⛔ 待做 |
| PUT | `/posts/{id}` | 编辑（**仅作者本人**，且有编辑时限） | ⛔ 待做 |
| DELETE | `/posts/{id}` | 删帖（软删） | ⛔ 待做 |
| POST | `/posts/{id}/reactions` | 帖子点赞 / 收藏 | ⛔ 待做 |
| POST | `/posts/{id}/view` | 记一次浏览（**要防刷**，同 IP 同帖 N 分钟内只记一次） | ⛔ 待做 |

### 后台（`/api/v1/admin/forum`）—— R19，不在本模块范围内

| 方法 | 路径 | 说明 | 状态 |
|---|---|---|---|
| POST | `/posts/{id}/pin` | 置顶 / 取消 | ⛔ 待做 |
| POST | `/posts/{id}/lock` | 锁定（不能再回复） | ⛔ 待做 |
| POST | `/posts/{id}/move` | 移到别的版块 | ⛔ 待做 |
| POST | `/boards` 等 | 版块增删改 | ⛔ 待做 |

## 数据表

### `boards`

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | bigint PK | |
| `slug` | string(40) unique | |
| `name` / `desc` | string | |
| `sort` | int | 排序，越小越前 |
| `min_role` | string 默认 `user` | 谁能看（预留，本期不判） |
| `postable` | bool 默认 true | 谁能发（预留） |

### `forum_posts`

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | bigint PK | |
| `slug` | string(160) unique | 可随后续改名（与博客不同） |
| `board_id` | bigint FK→boards | |
| `author_id` | bigint FK→users | |
| `title` | string(160) | |
| `excerpt` | string(255) | 空则从正文截 |
| `body_md` / `body_html` | longtext | 同博客：MD 是源，HTML 是保存时渲的缓存 |
| `tags` | json null | |
| `pin` | tinyint 默认 0 | 置顶 |
| `locked` | bool 默认 false | 锁定后不能再回复 |
| `status` | enum | `draft` / `published` / `hidden` |
| `view_count` / `like_count` / `favorite_count` / `reply_count` | int 默认 0 | 冗余计数 |
| `last_reply_at` / `last_reply_user_id` | | 列表要按"最后回复"排序，**必须冗余**，不能每次去 `comments` 表现算 |
| `created_at` / `updated_at` / `deleted_at` | | 软删 |

索引：`(board_id, pin desc, last_reply_at desc)`、`(status, last_reply_at desc)`。

### 复用（不新建）

- **回复** → `comments` 表，`target_type='forum_post'`
- **互动** → `reactions` 表，`target_type='forum_post' | 'comment'`

## 前端页面（`face/`）

| 路由 | 页面 | 干什么 | 状态 |
|---|---|---|---|
| `/forum` | 论坛首页 | 统计条 + 版块筛选（`?board=`）+ 帖子列表（置顶在最前） | ✅ |
| `/forum/{slug}` | 帖子详情 | 面包屑 + 正文 + 互动 + **评论树** + 同版其它帖 | ✅ |
| `/forum/new` | 发帖 | 选版块 + 标题 + Markdown 正文 + 预览 | 🟡 半成品 |

数据层 `app/composables/useForum.ts` —— 函数签名与上面接口一一对应。

## 待办

### 后端（`core/app/Modules/Forum/`）

- [ ] 迁移：`boards` / `forum_posts`（回复与互动复用模块 10 的表）
- [ ] 模型与关系；`ForumPostResource`（含 `author` / `board` / 冗余计数）
- [ ] **`last_reply_at` 维护**：新回复时同事务更新帖子表的这个字段（列表排序全靠它）
- [ ] `view_count` 防刷（同 IP 同帖 30 分钟内只记一次）
- [ ] 公开读：版块、帖子列表（分页 / 版块 / 关键词 / 排序）、详情、统计
- [ ] 会员写：发帖、编辑（作者 + 时限）、删除、点赞、浏览
- [ ] 通知：有人回复你的帖子 / 有人 @ 你，落一条给作者，唯一索引防刷
- [ ] 缓存：**详情页不预渲染**，按帖 / 按版块失效；列表短 TTL
- [ ] 限流：发帖（防刷屏）、回复、浏览
- [ ] 权限：仅作者可改删；`locked` 的帖子拒绝新回复；R19 的置顶/锁定/移动
- [ ] 搜索：标题 + 正文（先 LIKE，量级上来再上全文索引）

### 前端（`face/`）

**已完成：**

- [x] 数据层 `useForum.ts`：5 版块 + 46 帖，确定性伪随机生成
- [x] `/forum`：统计条、版块筛选、帖子列表（置顶优先 + 按最后回复倒序）
- [x] `/forum/{slug}`：正文 + 互动 + **评论树**（复用插件，`target_type='forum_post'`）
- [x] `/forum/new`：表单 + Markdown 预览
- [x] 帖子列表行是**真 `<a href>`**（预渲染靠它爬、键盘也能 Tab）

**半成品（都记着）：**

- [ ] **发帖没接接口** —— 点"发布"只弹个 alert，不落库
- [ ] **互动不落库** —— 点赞/收藏只改本地数字，刷新即回
- [ ] **没有上传** —— 帖子配图、附件都没有
- [ ] **未登录拦截没做** —— `/forum/new` 现在谁都能打开
- [ ] **浏览数不增长** —— `view_count` 是假数据，没有 POST `/view`
- [ ] **没有分页** —— 46 帖一次性渲染完，真实量级要改
- [ ] **没有搜索 / 排序切换** —— 后端接口设计好了，前端还没做 UI
- [ ] **没有帖子编辑页** —— `/forum/edit/{id}` 还没建

**还没开始：**

- [ ] 接真实接口（`useForum.ts` 换 `apiRequest`）
- [ ] **把 `/forum/{slug}` 排除出预渲染**（现在会被 `nuxt generate` 静态化，与"高频写入"的策略相反）
- [ ] 版块独立页（现在是 `?board=` 查询参数）
- [ ] 帖子搜索框、排序切换（最新 / 最热）
- [ ] 草稿保存
- [ ] R19 的版主操作入口（置顶 / 锁定 / 移动）

## 相关

- [10-blog.md](10-blog.md) —— 博客；**评论系统**的完整设计（决策 9）在这里
- [02-content.md](02-content.md) —— 缓存策略（博客与论坛相反）
- [../requirements.md](../requirements.md) —— R13 / R14 / R19
