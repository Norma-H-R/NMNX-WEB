# 模块 2 · Content（官网内容）

> 文档版本：`0.1.0` · 最后更新：`2026-10-03`

## 状态

**未开始**。目前只有占位路由 `GET /api/v1/public/ping`。

## 职责

官网 `face` 展示的动态数据：**文章 / 动态 / 公告**。

**本模块横跨两个守卫层**，这是它和其他模块不同的地方：

| 动作 | 走哪层 | 鉴权 |
|---|---|---|
| 读取（列表、详情） | `/api/v1/public` | 无 |
| 增删改（后台发布） | `/api/v1/admin` | 令牌 |

**不负责**：页面的静态外壳与动效（那是 `face/` 的构建期产物）；不做 SEO 相关的 HTML 渲染（官网自己 SSR）。

## 对外接口

### 公开读（`/api/v1/public`）—— 全部待做

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/posts` | 文章列表，分页，支持按标签/分类筛选 |
| GET | `/posts/{slug}` | 文章详情 |
| GET | `/moments` | 动态（短内容）列表 |
| GET | `/announcements` | 公告列表 |

### 后台写（`/api/v1/admin`）—— 全部待做

| 方法 | 路径 | 说明 |
|---|---|---|
| POST / PUT / DELETE | `/posts…` | 文章增删改 |
| POST / PUT / DELETE | `/moments…` | 动态增删改 |

> 响应体格式统一由**模块 0 Support** 定义，这里不另立一套。

## 缓存策略（重要，先定好再写代码）

官网采用 **「静态外壳 + 动态数据」**：

- 首页布局、动效、星场是**构建期产物**，扔 CDN，永不重渲
- 文章 / 动态 / 公告走 API **异步拉取**
- 后台发布内容时只做 **「数据失效」**，不做「页面失效」：
  1. 在**保存请求内**同步清 Redis 缓存（毫秒级，用户立刻能看到）
  2. 再**异步走队列**刷 CDN（失败可重试）
  3. **绝不重新生成整页 HTML**

列表用**短 TTL**（30s~2min）兜底；详情页可按 `slug` 做细粒度失效。

> 博客与论坛策略相反，必须分开：博客低频写入、可静态预渲染详情页、按 tag 清列表；论坛高频写入、详情页不做静态、按帖/按版块失效。**严禁**"每次回复触发全站缓存失效"。

## 关键类与函数

**规划中**（还没建）：

| 计划位置 | 用途 |
|---|---|
| `app/Modules/Content/Models/Post.php` 等 | Eloquent 模型 |
| `app/Modules/Content/Http/Controllers/PublicPostController.php` | 公开读 |
| `app/Modules/Content/Http/Controllers/AdminPostController.php` | 后台写 |
| `app/Modules/Content/Http/Resources/PostResource.php` | 出参整形（**不要**直接把模型吐出去，避免字段泄漏） |
| `app/Modules/Content/Services/ContentCache.php` | 缓存读写 + 失效，统一入口 |
| `app/Modules/Support/Contracts/CdnPurger.php` | CDN 刷新抽象（**尚未选定 CDN**，先留接口） |

## 数据表

**规划中**：

| 表 | 关键字段 |
|---|---|
| `posts` | `id` `slug`(唯一) `title` `excerpt` `body` `status`(draft/published) `published_at` `author_id` |
| `moments` | `id` `content` `published_at` |
| `announcements` | `id` `title` `body` `level` `starts_at` `ends_at` |
| 标签/分类 | 视需要再加，别提前设计 |

## 配置与环境变量

| 配置 | 说明 |
|---|---|
| 缓存 TTL | 建议常量集中定义，不要散落在控制器里 |
| CDN 相关 | **未定**（暂不上 CDN），预留 `CdnPurger` 抽象 |

## 待办

- [ ] 定文章/动态/公告的字段与状态机（草稿 / 已发布 / 下架）
- [ ] 写迁移与模型
- [ ] 公开读接口（含分页、筛选）
- [ ] 缓存层 + 失效逻辑（先只做 Redis tag 失效，CDN 那步等上了 CDN 再接）
- [ ] 后台写接口 + 校验
- [ ] 定 CDN 方案后再实现 `CdnPurger`
