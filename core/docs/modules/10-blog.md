# 模块 10 · 博客（难点）（Blog）

> 文档版本：`0.4.0` · 最后更新：`2026-10-04`

## 状态

**后端接口已落地并实测通过（只差后台与上传）；`face/` 仍读假数据，待第 ④ 步对接。**

| 端 | 情况 |
|---|---|
| `face/`（官网） | 公共博客列表、博客详情、我的博客、编辑器四个页面已按本文档实现。数据来自 `app/composables/useBlog.ts` 里的**假数据（50 篇）**，接口一落地，把那一层换成 `apiRequest` 即可，页面不用动。 |
| `core/`（后端） | **公开读 / 会员写 / 评论 / 互动已实现并实测通过**（3 个 Service、3 个 Controller、3 个 Request、16 条路由；见 CHANGELOG `0.23.0`）。**还差两块**：后台 `/api/v1/admin/blogs`（R19，本文档已注明"暂不在本模块范围内"）、`POST /api/v1/member/uploads`（需先定文件存储策略）。 |
| **评论区** | **独立的系统**，不是博客的附属。本期随博客一起做，但设计上必须能**原样复用到论坛**（见决策 9）。**已按此落地**：`/api/v1/comments` 里没有 "blog" 这个词，论坛接入只需换一个 `target_type`。 |

需求编号：**R12**（博客模块，难点需专门设计）、**R14**（博客与论坛互通）、**R19**（官方对博客、论坛的管理）。
见 [../requirements.md](../requirements.md)，改需求说编号即可。

## 职责

**做什么：**

- 会员**个人博客**：新建、编辑、删除、发布 / 转草稿、下架
- 公开浏览：任何人的博客都能看（列表 + 详情），无需登录
- **互动三态**：点赞 / 收藏 / 拉黑（读者对博客），作者在自己的列表页能看到三项累计数
- 互动事件进**通知中心**（"你的博客被点赞 / 被收藏 / 被拉黑"）
- 正文支持 **Markdown** + 附件（图片 / 音频 / 视频 / doc / xls / pdf）
- **评论**：读者对博客发评论；**评论本身也带 点赞 / 踩 / 收藏**（用户明确要求，见决策 8）

**不负责（边界，最容易搞混）：**

- **官方的文章 / 动态 / 公告** → 模块 02 Content（R10 / R18）。博客是**会员 UGC**，文章是**官方发布**，
  两张表、两套接口，别混在一起做
- **论坛**（帖子、版块）→ 模块 11 Forum（R13）
- **论坛帖里引用博客** → R14，本期只在博客侧留 `allow_reference` 字段，论坛那边落地时再接
- **文件存储本身**（对象存储、CDN、病毒扫描）→ 见下面「附件与媒体」
- 官方对博客的**审核 / 封禁 / 下线** → R19，归后台（`admin/` + `/api/v1/admin/blogs`）

## 关键架构决策（用户已拍板 / 需要定下来）

1. **博客 ≠ 文章。** 博客是会员写的，文章是官方发的。两者的表、接口、缓存策略都分开。
   唯一共用的东西是「附件」和「Markdown 渲染」这两块能力。
2. **正文存 Markdown 源，另存一份渲染好的 HTML。**
   - `body_md` 是唯一真实来源（作者再次编辑的时候要用它）
   - `body_html` 是**保存时用服务端渲染器**（`league/commonmark`，已在 vendor 里）生成的缓存，
     列表和详情直接吐它，避免每次请求都渲染一遍
   - **前端不用 `v-html` 直接吃后端 HTML 之外的任何东西**（XSS）。渲染一律走服务端，前端只负责展示
3. **互动三态是独立的，不做互斥。**
   `like` / `favorite` 是正向，`block` 是负向（"不想再看到这类内容"）。
   一个人可以同时点赞 + 收藏；拉黑是独立的一票。三者都记在 `blog_reactions`，
   用 `(blog_id, user_id, type)` 唯一索引兜住重复。
4. **计数字段冗余在 `blogs` 表上**（`like_count` / `favorite_count` / `block_count` / `view_count`）。
   列表页要"一眼看到多少赞多少藏"，每次都 `COUNT(*)` 会拖垮列表查询。
   一致性策略：互动写入在**同一事务**里 `+1/-1` 并写 `blog_reactions`；对账用命令 `blog:recount`（待做）。
5. **`slug` 而不是自增 id 做详情页的对外标识。** 详情页要做静态预渲染，可读的 URL 才有意义。
   规则：`{slug}` 全局唯一，创建时从标题生成，冲突加短后缀；**发布后不可改**（改了旧链接就断）。
6. **缓存策略与论坛相反**（这条在 [02-content.md](02-content.md) 里已经定过，照做）：
   - 博客**低频写入** → 详情页可静态预渲染，按 `slug` 细粒度失效
   - 列表按 **tag** 清（`blog:list:*`）
   - **严禁**"任一次互动就全站失效" —— 点赞只更新计数，不碰缓存
7. **「拉黑」只作用于这一篇博客**（用户已明确）。
   它是读者的一个负面标记（"这篇我不想再看到 / 不感兴趣"），**不是**人际关系上的拉黑：
   - **不**影响该作者的其它博客对该读者的可见性
   - **不**影响作者能不能评论、能不能被搜索
   - **不公开**：只有作者在自己列表页能看到"被拉黑 N 次"，读者侧不展示是谁
   > 将来若要做"屏蔽某个作者"，那是另一个功能（`user_blocks`），别往这张表上塞。
8. **封面强制上传，缺省用占位块**（用户已明确）。
   - `cover_url` 由作者上传，**不做**"从正文自动抽第一张图"
   - 没传时前端渲染**空白占位元素**（同尺寸的斜纹块），不报错、不留空洞，
     列表卡片的网格因此不会被撑歪
   - 后端 `cover_url` 允许为 null —— 校验放在「发布」这一步，草稿可以不带封面
9. **评论是一套独立的系统，不是博客的附属功能**（用户已明确）。
   ⚠️ **论坛将来要直接复用它** —— 所以设计上不许出现任何"博客专属"的东西：

   - **多态目标**：评论表用 `target_type` + `target_id` 挂到任意对象上。
     本期只有 `blog`；论坛落地时加一个 `forum_post` 即可，**表结构一行不用动**
   - **无限嵌套**（用户明确要求，参考 Reddit）：
     - 存储用 `parent_id` + **物化路径 `path`**（形如 `0000012/0000451/`）。
       取某条评论的整棵子树 = `path LIKE '0000012/%'`，**一次查询拿完，不用递归**
     - `depth` 冗余存层级：前端折叠、后端限制都用它，省得每次去数斜杠
     - **展示**：超过 6 层折叠成"继续查看" —— 无限缩进在窄屏上根本没法看，
       Reddit 自己也这么做。**存储无限、展示有度**
   - **评论自带三种互动**：`like`（赞）/ `dislike`（踩）/ `favorite`（收藏），
     与博客互动**共用 `reactions` 表**（`target_type = comment`）
   - **接口是通用的**：`/api/v1/comments?target_type=…&target_id=…`。
     不写成 `/blogs/{id}/comments` —— 那样论坛就得再来一套
   - **前端是独立组件** `CommentThread.vue`（递归渲染）。博客详情页只是把它插进去，
     论坛复用时同样只是插进去 —— 这个组件**不认识"博客"这个概念**
   - 视觉参考：**GitHub 的评论卡片 + Reddit 的树形缩进**
     （头像 + 用户名 + 时间 + 正文 + 操作行，左侧竖线表示层级）
   - 通知：新评论给目标对象作者，回复额外给被回复的人

## 对外接口

> 响应体格式统一由**模块 00 Support** 定义（`{ code, message, data, meta }`），这里不另立一套。
> 状态图例：✅ 已完成 / 🟡 部分 / ⛔ 待做

### 公开读（`/api/v1/public`）—— 无需登录

| 方法 | 路径 | 鉴权 | 说明 | 状态 |
|---|---|---|---|---|
| GET | `/blogs` | 无 | 博客列表：分页、按 tag / 关键词筛、`sort=latest\|hot` | ⛔ 待做 |
| GET | `/blogs/{slug}` | 无（可选带 token 以回显"我点过赞没"） | 博客详情 | ⛔ 待做 |
| GET | `/blogs/{slug}/related` | 无 | 相关推荐（同 tag，取 N 条） | ⛔ 待做 |
| GET | `/comments` | 无（可选带 token 以回显"我点过赞没"） | **通用评论树**：`?target_type=blog&target_id=12&sort=hot\|new`。论坛复用同一接口 | ⛔ 待做 |

`blog` 对象（`BlogResource`）：

```json
{
  "id": 12, "slug": "dianzhen-yanshi-3ms", "title": "点阵延迟 3ms 是怎么测出来的",
  "excerpt": "端到端，从数据落库到点阵出下一帧…",
  "cover": "https://…/cover.webp",
  "author": { "id": 2, "name": "南门会员", "avatar": null },
  "tags": ["性能", "点阵"],
  "stats": { "like": 128, "favorite": 46, "block": 3, "view": 2140, "comment": 7 },
  "mine": { "liked": false, "favorited": false, "blocked": false },
  "atts": [ { "kind": "image", "url": "…", "name": "chart.png", "size": 102400 } ],
  "published_at": "2026-10-02T21:40:00+08:00",
  "updated_at": "2026-10-03T09:12:00+08:00"
}
```

> 详情接口的 `data` 里**额外带 `body_html`**（列表不带，省流量）。
> `mine` 只在带令牌时返回，未登录时为 `null`。

### 会员写（`/api/v1/member/blogs`）—— `auth:member`

| 方法 | 路径 | 说明 | 状态 |
|---|---|---|---|
| GET | `/blogs` | **我自己的**博客列表（含草稿），带 `stats` | ⛔ 待做 |
| GET | `/blogs/stats` | 我的累计：总赞 / 总藏 / 总拉黑 / 总浏览 / 篇数 | ⛔ 待做 |
| POST | `/blogs` | 新建（可存草稿，也可直接发布） | ⛔ 待做 |
| PUT | `/blogs/{id}` | 编辑（**仅作者本人**） | ⛔ 待做 |
| DELETE | `/blogs/{id}` | 删除（软删） | ⛔ 待做 |
| POST | `/blogs/{id}/publish` | 发布 / 转草稿 | ⛔ 待做 |
| POST | `/blogs/{id}/reactions` | 点赞 / 收藏 / 拉黑（见下） | ⛔ 待做 |
| DELETE | `/blogs/{id}/reactions` | 取消某个互动 | ⛔ 待做 |

互动接口的请求形态：

```
POST /api/v1/member/blogs/12/reactions
{ "type": "like" }        // like | favorite | block
→ data: { "stats": { "like": 129, "favorite": 46, "block": 3 }, "mine": { "liked": true, … } }
```

- **自己不能给自己的博客互动**（后端拦，返回 `BLOG_SELF_REACTION`）
- 重复点同一个 `type` 不报错，幂等返回当前状态（前端乐观更新后对账用）

### 评论（**通用**，`/api/v1`）—— 列表公开，写操作要 `auth:member`

| 方法 | 路径 | 鉴权 | 说明 | 状态 |
|---|---|---|---|---|
| GET | `/comments` | 无 | 评论树（见上面公开读一栏） | ⛔ 待做 |
| POST | `/comments` | `auth:member` | 发表：`{ target_type, target_id, parent_id?, body }` | ⛔ 待做 |
| DELETE | `/comments/{id}` | `auth:member` | 删评论（评论作者本人；目标对象作者可删自己名下的） | ⛔ 待做 |
| POST | `/comments/{id}/reactions` | `auth:member` | 互动：`{ type: "like" \| "dislike" \| "favorite" }` | ⛔ 待做 |
| DELETE | `/comments/{id}/reactions` | `auth:member` | 取消某个互动 | ⛔ 待做 |

**路径里不出现 `blog`** —— 论坛落地时只换一个 `target_type`，不新增接口。

评论树的出参形态：

```json
{
  "total": 42,
  "items": [
    {
      "id": 451, "parent_id": null, "depth": 1, "path": "0000451/",
      "user": { "id": 7, "name": "星河" },
      "body": "直接在新机器上激活就行…",
      "stats": { "like": 12, "dislike": 0, "favorite": 3, "reply": 2 },
      "mine": { "liked": false, "disliked": false, "favorited": false },
      "created_at": "…",
      "children": [ { "id": 455, "parent_id": 451, "depth": 2, "…": "…", "children": [] } ]
    }
  ]
}
```

- 顶层**分页**（`per_page` 默认 20，`sort=hot|new`）；**子级随父一次带出** ——
  用 `path LIKE` 一次查完再在内存里拼树，**不做 N+1 递归**
- 单次返回的子树深度后端截到 **6 层**，更深的带上 `has_more: true`，
  前端点"继续查看"时按 `parent_id` 再拉一段
- 评论互动与博客互动同构：同一张 `reactions` 表、同样的幂等规则与禁自赞规则

### 上传（`/api/v1/member/uploads`）—— `auth:member`

| 方法 | 路径 | 说明 | 状态 |
|---|---|---|---|
| POST | `/uploads` | 上传单个文件，`multipart/form-data`，返回 `{ url, kind, name, size, mime }` | ⛔ 待做 |

`kind` 由后端按 MIME 判定：`image` / `audio` / `video` / `doc` / `sheet` / `pdf` / `other`。
**不要在前端按扩展名猜** —— 前端只负责把 `kind` 渲染成对应卡片。

### 后台（`/api/v1/admin/blogs`）—— R19，暂不在本模块范围内

| 方法 | 路径 | 说明 | 状态 |
|---|---|---|---|
| GET | `/blogs` | 全部博客（跨会员） | ⛔ 待做 |
| POST | `/blogs/{id}/hide` | 下架 / 恢复 | ⛔ 待做 |

## 数据表

### `blogs`

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | bigint PK | |
| `slug` | string(120) **unique** | 对外标识，发布后不可改 |
| `author_id` | bigint FK→users | |
| `title` | string(160) | |
| `excerpt` | string(255) | 列表摘要，空则从正文截前 120 字 |
| `body_md` | longtext | **Markdown 源，唯一真实来源** |
| `body_html` | longtext | 保存时渲染好的 HTML（缓存） |
| `cover_url` | string(255) null | 封面。**可为 null**：没传时前端渲染空白占位块（决策 8），"必须有封面"的校验放在发布那一步 |
| `tags` | json null | 标签数组（不做独立表，够用） |
| `status` | enum | `draft` / `published` / `hidden`（官方下架） |
| `allow_reference` | bool 默认 true | **R14**：允许论坛引用 |
| `like_count` / `favorite_count` / `block_count` / `view_count` / `comment_count` | int 默认 0 | 冗余计数 |
| `published_at` | timestamp null | |
| `created_at` / `updated_at` / `deleted_at` | | 软删 |

索引：`(status, published_at desc)`、`(author_id, status)`、`tag` 用 JSON 的话不建索引（量级够用）。

### `reactions`（博客与评论**共用**一张）

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | bigint PK | |
| `target_type` | enum | `blog` / `comment` |
| `target_id` | bigint | 指向 `blogs.id` 或 `blog_comments.id` |
| `user_id` | bigint FK | |
| `type` | enum | 博客：`like` / `favorite` / `block`；评论：`like` / `dislike` / `favorite` |
| `created_at` | | |

**唯一索引 `(target_type, target_id, user_id, type)`** —— 幂等靠它兜。

> 两个 target 的 `type` 取值**不同**（博客有 `block`、没有 `dislike`；评论反过来）。
> 取值校验放在各自的控制器里，**不要**为了"统一"把两边的语义凑成一样。

### `blog_attachments`

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | bigint PK | |
| `blog_id` | bigint FK null | 先传后写时为 null，保存博客时回填 |
| `user_id` | bigint FK | 归属，防止引用别人的文件 |
| `kind` | enum | `image` / `audio` / `video` / `doc` / `sheet` / `pdf` / `other` |
| `url` / `name` / `size` / `mime` | | |

### `comments`（**独立系统**，博客与论坛共用 —— 见决策 9）

| 字段 | 类型 | 说明 |
|---|---|---|
| `id` | bigint PK | |
| `target_type` | enum | 目标类型：`blog`（本期）/ `forum_post`（论坛落地时加） |
| `target_id` | bigint | 目标 id。**多态，不建外键** —— 目标分属不同表 |
| `user_id` | bigint FK | 评论者 |
| `parent_id` | bigint null | 父评论；顶层为 null。**不做层级限制** |
| `path` | string(255) | **物化路径**，形如 `0000012/0000451/`；取子树用 `path LIKE '0000012/%'` |
| `depth` | smallint | 层级（顶层 = 1）。冗余存，供折叠与限制使用 |
| `body` | text | 纯文本 —— 评论不支持 Markdown，别再开一套渲染面 |
| `like_count` / `dislike_count` / `favorite_count` | int 默认 0 | 冗余计数 |
| `reply_count` | int 默认 0 | 直接子评论数（列表里显示"3 条回复"） |
| `created_at` / `updated_at` / `deleted_at` | | 软删 |

索引：

- `(target_type, target_id, parent_id, created_at)` —— 取某目标下的评论
- `(path)` —— 取子树

> **删除要小心**：软删时**不要物理删子孙**，把正文清空、保留树形，
> 前端显示成"该评论已删除"。否则子评论会变成找不到爹的孤儿。

## 前端页面（`face/`）

| 路由 | 页面 | 干什么 | 状态 |
|---|---|---|---|
| `/blog` | 公共博客列表 | 任何人的博客，卡片上**就地**能点赞 / 收藏 / 拉黑 | ✅ |
| `/blog/{slug}` | 博客详情 | 正文 + 附件渲染 + 互动 + **评论区**（评论自带赞/踩/收藏）+ 相关推荐 | ✅ |
| `/blog/mine` | 我的博客 | **仅自己可见**：草稿/已发布分栏，每篇带赞/藏/拉黑数，底部汇总 | ✅ |
| `/blog/new` | 新建 | 编辑器 | ✅ |
| `/blog/edit/{id}` | 编辑已有 | 同一个编辑器组件 | ✅ |

> 路由上 `/blog/mine`、`/blog/new` 是静态段，优先于 `/blog/{slug}`，不会被打架。
> 未登录访问 `/blog/mine`、`/blog/new`、`/blog/edit/*` → 走闸门过渡回 `/account` 登录。

数据层：`face/app/composables/useBlog.ts`
- 现在是**假数据**（50 篇，含图文 / 音频 / 视频 / doc / xls 附件）
- 对外暴露的函数签名**与上面接口一一对应**，接口落地时只需替换函数体

组件（评论相关的必须写成**通用件**，论坛直接拿去用）：

| 组件 | 职责 | 用在哪 |
|---|---|---|
| `CommentThread.vue` | 评论树：递归渲染、折叠、发表框、分页 | 博客详情 / 未来的论坛帖 |
| `CommentItem.vue` | 单条评论：赞 / 踩 / 收藏、回复、删除 | 同上 |
| `ReactionBar.vue` | 一组互动按钮（赞 / 踩 / 收藏 / 拉黑），带乐观更新 | 博客卡片、博客详情、评论 |

> `CommentThread.vue` **只认两个 props**：`targetType` / `targetId`。
> 它不认识"博客"这个词 —— 这是它将来能直接插进论坛页的前提。

## 富文本编辑器与附件

### 选型（已定）

**`md-editor-v3`**（MIT，Vue 3 原生组件）。

选它的理由：

1. **纯 Markdown 优先** —— 正文以 `body_md` 为唯一真实来源，编辑器必须原生就是 MD，
   而不是"富文本再导出 MD"（那种来回转换一定会丢格式）
2. Vue 3 原生，不依赖 React / jQuery 运行时的桥接
3. 自带：目录、代码高亮、表格、公式、预览、**图片上传钩子 `onUploadImg`**
4. MIT，活跃维护，可直接用 npm 装，不引私有源

备选：**Vditor**（如果你更想要"所见即所得"的即时渲染模式）。两者都是成熟库，
差别在交互风格 —— 现在的实现按 `md-editor-v3` 写，换 Vditor 只动编辑器组件一处。

### 附件怎么进正文

用户要求支持 doc / excel / 图片 / 音频 / 视频。做法是**统一走上传 + Markdown 链接**，
渲染时按 `kind` 出不同卡片：

| kind | 渲染成 |
|---|---|
| `image` | 图片（点击放大） |
| `audio` | 内嵌播放器 |
| `video` | 内嵌播放器 |
| `pdf` / `doc` / `sheet` | 文件卡片：图标 + 文件名 + 大小 + **下载**按钮 |
| `other` | 同上 |

**不做**的：在线编辑 Word / Excel（那是 OnlyOffice / Collabora 这类整套文档服务的活，
与本项目的体量不匹配）。这里只保证"能传、能嵌、能下载"。

### XSS 底线（别在这里省事）

- 渲染 HTML 一律**服务端**做（`league/commonmark` + 白名单扩展）
- 前端**不**用 `v-html` 吃任何用户输入的原始 HTML
- 上传要校验 MIME 白名单 + 大小上限，图片要重编码（去掉 EXIF 和潜在的 HTML 伪装）

## 通知接入

三态互动都要落一条通知给**作者**（不是给操作者）：

| 事件 | 通知文案 | 去哪 |
|---|---|---|
| 点赞 | `{谁} 点赞了你的博客「{标题}」` | 通知中心 + 页头未读（`reply` 类） |
| 收藏 | `{谁} 收藏了你的博客「{标题}」` | 同上 |
| 拉黑 | `{谁} 拉黑了你的博客「{标题}」` | 同上（**作者可见，不公开**） |

- 自己操作自己的不产生通知
- 通知的落库/去重按 `(user_id, type, blog_id, actor_id)` 唯一，防刷
- 前端侧：`face/app/composables/useNotices.ts` 里 `kind: 'reply'` 那一类将来换成
  通知接口的数据，页面不用改

## 待办

### 后端（`core/app/Modules/Blog/`）

- [ ] 迁移：`blogs` / `blog_reactions` / `blog_attachments` 三张表
- [ ] 模型 + 关系（`Blog` / `BlogReaction` / `BlogAttachment`）
- [ ] `BlogResource`：出参整形（含 `stats` / `mine` / `atts`）
- [ ] `MarkdownRenderer` 服务：`body_md` → `body_html`（保存时渲染，别放在读取路径上）
- [ ] 公开读接口：列表（分页 / tag / 关键词 / 排序）、详情、相关推荐
- [ ] 会员写接口：我的列表、汇总统计、增删改、发布/转草稿
- [ ] 互动接口：写 `blog_reactions` + 同事务更新计数（幂等、禁自赞）
- [ ] 上传接口：MIME 白名单、大小上限、`kind` 判定、图片重编码
- [ ] 缓存层：按 `slug` 失效详情，按 tag 清列表；**互动不改缓存**
- [ ] `blog:recount` 对账命令（计数与 `blog_reactions` 不符时修复）
- [ ] **评论通用接口**（`/api/v1/comments`）：树形取数（`path LIKE` 一次查完 + 内存拼树，**禁 N+1 递归**）、发表、删除
- [ ] **评论路径维护**：写入时算 `path` / `depth`，父评论的 `reply_count` 同步 +1
- [ ] 评论互动：赞 / 踩 / 收藏（复用 `reactions` 表 + 同事务更新计数）
- [ ] 通知接入：博客三态 + 评论 + 回复，各自落一条，唯一索引防刷
- [ ] 限流：新建/编辑（做人不能刷屏）、互动（防脚本刷赞）
- [ ] 权限：仅作者可改删，`hidden` 只有官方能设

### 前端（`face/`）

**已完成：**

- [x] 假数据层 `useBlog.ts` —— **50 篇 + 100 条评论**，用**确定性伪随机**生成
      （`Math.random` 会让 SSR 与客户端各算出不一样的结果，直接撞 hydration）
- [x] 公共列表 `/blog` —— 卡片就地点赞 / 收藏 / 拉黑、最新 / 最热排序、封面占位块
- [x] 详情 `/blog/{slug}` —— Markdown 正文、附件卡片（图 / 音频 / 视频 / doc / xls / pdf）、互动、相关推荐
- [x] 我的博客 `/blog/mine` —— 草稿 / 已发布分栏、每篇三项计数 + 汇总
- [x] 新建 / 编辑 `/blog/new`、`/blog/edit/{id}` —— 共用 `BlogEditor.vue`
- [x] `CommentThread.vue` —— **已写成通用件**（只认 `targetType` / `targetId`，不认识"博客"）
- [x] 评论树：DFS 拍平 + 缩进渲染、层级竖线、折叠 / 展开、赞 / 踩 / 收藏、发表框
      （实测 blog-1：18 条评论、5 层嵌套）

**半成品（都记着，别当已完成）：**

- [ ] **编辑器不是 `md-editor-v3`** —— 现在是自建的 textarea + marked 预览。
      换库只动 `BlogEditor.vue` 一个文件，页面不用动
- [ ] **互动 / 发表都不落库** —— 目前只改本地数字，刷新即回。真实环境要接接口 + 失败回滚
- [ ] **上传没接** —— 封面用的是 `URL.createObjectURL` 本地预览；附件区只是一段说明，
      上传按钮还没做（要接 `POST /api/v1/member/uploads`）
- [ ] **评论没接后端** —— 发表只往本地数组推一条，`path` / `depth` 都没算
- [ ] **未登录拦截没做** —— `/blog/mine`、`/blog/new`、`/blog/edit/*` 现在谁都能打开
- [ ] **详情页 XSS** —— 现在拿 `marked` 直接渲染（只对本地假数据成立）。
      正式环境必须用服务端渲染好的 `body_html`，见上面「XSS 底线」

**还没开始：**

- [ ] **接真实接口**：把 `useBlog.ts` 的假数据换成 `apiRequest`（接口一好就能接）
- [ ] 列表分页 / 无限滚动（现在一次性渲染 50 篇；真实量级要改）
- [ ] 评论的"继续查看"：超过 6 层时按 `parent_id` 再拉一段（现在 6 层内一次带全）
- [ ] 相关推荐走 `/blogs/{slug}/related`（现在是前端自己按 tag 筛）
- [ ] SEO（`useHead` 的 description / og）、详情页预渲染
- [ ] `ReactionBar.vue` 抽成独立组件（现在列表页与详情页各写了一份互动按钮）

### 待定的问题（要用户拍板）

- [ ] **`slug` 生成规则**：拼音 / 随机短码 / 允许作者自填？（现在假数据用的是手写 slug）
- [x] ~~拉黑的语义~~ → **已定**：只对**这一篇**（决策 7）
- [x] ~~封面~~ → **已定**：强制上传，没传时前端渲染占位块（决策 8）
- [x] ~~评论~~ → **已定**：**要做**，且评论本身带 赞 / 踩 / 收藏（决策 9）

## 相关

- [02-content.md](02-content.md) —— 官方文章 / 动态 / 公告（和博客的边界在这里）
- [11-forum.md](11-forum.md) —— 论坛（R14 互通在这边落地）
- [00-support.md](00-support.md) —— 统一响应体
- [../requirements.md](../requirements.md) —— R12 / R14 / R19
