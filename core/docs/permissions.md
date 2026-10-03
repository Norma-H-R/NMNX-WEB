# 权限点总表（121 个 / 14 组）

> ⚠️ **本文件由命令生成，不要手改。**
> 数据在数据库的 `permissions` / `roles` / `role_permissions` 三张表里。
> 后台「权限点管理」里改完，跑一次 `php artisan rbac:doc` 即可同步。
> 落地方式与推进计划见 `docs/permissions-model.md`。

## 一、总览

| 分组 | 个数 |
|---|---|
| 用户管理 | 13 |
| 博客（用户投稿） | 10 |
| 文章（官方发布） | 10 |
| 公告 | 7 |
| 反馈 | 6 |
| 论坛 | 16 |
| 自媒体 | 9 |
| 产品与授权 | 19 |
| 订单与支付 | 4 |
| 审计 | 7 |
| 统计 | 3 |
| 插件 | 5 |
| 密钥 | 3 |
| 系统 | 9 |
| **合计** | **121** |

## 二、角色默认基线

| 角色 | key | 说明 | 基线权限数 |
|---|---|---|---|
| 超级管理员 | `owner` | 拥有全部权限，且可以给其它管理员分配权限 | **隐含全部**（不存快照） |
| 管理员 | `admin` | 按被分配的权限点管理后台 | 19 |
| 论坛版主 | `moderator` | 管理论坛的帖子与评论 | 16 |
| 博客博主 | `blogger` | 撰写与管理自己的博客投稿 | 4 |
| 普通用户 | `member` | 仅在前台浏览与互动，不进后台 | 0 |

## 三、逐条清单

### 用户管理（13）

| 权限标识 | 名称 | 说明 | 来源 |
|---|---|---|---|
| `user.read` | 查看用户 | 浏览用户列表 | 内置 |
| `user.detail` | 查看用户详情 | 查看完整资料与业务数据 | 内置 |
| `user.edit` | 编辑用户资料 | 修改昵称、邮箱、简介等 | 内置 |
| `user.status` | 禁用 / 恢复 | 冻结或恢复账号 | 内置 |
| `user.mute` | 禁言 | 限制发言但允许登录 | 内置 |
| `user.ban` | 封禁账号 | 禁止其登录 | 内置 |
| `user.role` | 调整身份 | 变更用户身份（博主、版主、管理员） | 内置 |
| `user.permission` | 配置权限 | 给管理员逐项分配权限 | 内置 |
| `user.tag` | 打标签 | 给用户标记分组（大客户、内测等） | 内置 |
| `user.note` | 备注 | 添加内部备注，仅后台可见 | 内置 |
| `user.import` | 导入用户 | 批量导入账号 | 内置 |
| `user.export` | 导出用户 | 导出用户数据 | 内置 |
| `user.delete` | 删除用户 | 永久删除账号（不可恢复） | 内置 |

### 博客（用户投稿）（10）

| 权限标识 | 名称 | 说明 | 来源 |
|---|---|---|---|
| `blog.read` | 查看投稿 | 浏览用户投稿 | 内置 |
| `blog.create` | 撰写投稿 | 新建自己的博客投稿 | 内置 |
| `blog.review` | 审核投稿 | 通过或驳回待审稿件 | 内置 |
| `blog.edit` | 编辑投稿 | 修改正文与标题 | 内置 |
| `blog.publish` | 发布 / 下架 | 控制稿件是否对外可见 | 内置 |
| `blog.pin` | 置顶 | 在博客列表置顶 | 内置 |
| `blog.feature` | 加精 | 标记为精选 | 内置 |
| `blog.category` | 分类与标签 | 维护博客分类与标签 | 内置 |
| `blog.comment` | 评论管理 | 删除或折叠评论 | 内置 |
| `blog.delete` | 删除投稿 | 彻底删除稿件 | 内置 |

### 文章（官方发布）（10）

| 权限标识 | 名称 | 说明 | 来源 |
|---|---|---|---|
| `article.read` | 查看文章 | 浏览官方文章 | 内置 |
| `article.create` | 新建文章 | 撰写新文章 | 内置 |
| `article.edit` | 编辑文章 | 修改正文与排版 | 内置 |
| `article.preview` | 预览草稿 | 预览未发布的文章 | 内置 |
| `article.publish` | 发布 / 撤稿 | 控制上线状态 | 内置 |
| `article.schedule` | 定时发布 | 预约发布时间 | 内置 |
| `article.category` | 分类管理 | 维护文章分类 | 内置 |
| `article.revision` | 版本历史 | 查看与回滚历史版本 | 内置 |
| `article.seo` | SEO 设置 | 标题、描述、关键词 | 内置 |
| `article.delete` | 删除文章 | 彻底删除文章 | 内置 |

### 公告（7）

| 权限标识 | 名称 | 说明 | 来源 |
|---|---|---|---|
| `notice.read` | 查看公告 | 浏览公告列表 | 内置 |
| `notice.create` | 新建公告 | 撰写新公告 | 内置 |
| `notice.edit` | 编辑公告 | 修改公告内容 | 内置 |
| `notice.publish` | 发布 / 下线 | 控制是否展示 | 内置 |
| `notice.push` | 弹窗推送 | 以强提醒方式推送 | 内置 |
| `notice.target` | 定向推送 | 按身份或标签定向发布 | 内置 |
| `notice.delete` | 删除公告 | 彻底删除公告 | 内置 |

### 反馈（6）

| 权限标识 | 名称 | 说明 | 来源 |
|---|---|---|---|
| `feedback.read` | 查看反馈 | 浏览用户提交的反馈 | 内置 |
| `feedback.reply` | 回复反馈 | 回复用户的问题 | 内置 |
| `feedback.handle` | 处理反馈 | 标记为处理中 / 已解决 | 内置 |
| `feedback.close` | 关闭反馈 | 关闭不再跟进的反馈 | 内置 |
| `feedback.export` | 导出反馈 | 导出反馈数据 | 内置 |
| `feedback.delete` | 删除反馈 | 彻底删除反馈 | 内置 |

### 论坛（16）

| 权限标识 | 名称 | 说明 | 来源 |
|---|---|---|---|
| `forum.read` | 查看帖子 | 浏览论坛内容 | 内置 |
| `forum.board.create` | 创建版块 | 新增论坛版块 | 内置 |
| `forum.board.edit` | 编辑版块 | 修改版块名称与说明 | 内置 |
| `forum.board.delete` | 删除版块 | 删除版块（需先清空帖子） | 内置 |
| `forum.post.create` | 发帖 | 发布新主题 | 内置 |
| `forum.post.edit` | 编辑帖子 | 修改任意帖子内容 | 内置 |
| `forum.post.pin` | 置顶 | 在版块内置顶 | 内置 |
| `forum.post.feature` | 加精 | 标记为精华 | 内置 |
| `forum.post.highlight` | 高亮 | 标题高亮显示 | 内置 |
| `forum.post.move` | 移动帖子 | 在版块之间移动 | 内置 |
| `forum.post.lock` | 锁定帖子 | 禁止继续回复 | 内置 |
| `forum.post.delete` | 删除帖子 | 删除任意主题 | 内置 |
| `forum.reply.delete` | 删除回复 | 删除任意楼层 | 内置 |
| `forum.report` | 处理举报 | 查看并处置举报内容 | 内置 |
| `forum.user.mute` | 论坛禁言 | 限制某人在论坛发言 | 内置 |
| `forum.statistics` | 论坛数据 | 查看发帖与活跃统计 | 内置 |

### 自媒体（9）

| 权限标识 | 名称 | 说明 | 来源 |
|---|---|---|---|
| `media.read` | 查看渠道 | 浏览渠道与内容 | 内置 |
| `media.create` | 新建内容 | 创建待发布内容 | 内置 |
| `media.material` | 素材库 | 管理图片、视频素材 | 内置 |
| `media.schedule` | 定时发布 | 预约发布时间 | 内置 |
| `media.publish` | 发布 | 推送到渠道 | 内置 |
| `media.channel` | 渠道管理 | 绑定 / 解绑渠道账号 | 内置 |
| `media.comment` | 评论互动 | 回复渠道下的评论 | 内置 |
| `media.statistics` | 数据统计 | 查看阅读与转化数据 | 内置 |
| `media.delete` | 删除内容 | 删除已创建内容 | 内置 |

### 产品与授权（19）

| 权限标识 | 名称 | 说明 | 来源 |
|---|---|---|---|
| `product.read` | 查看产品 | 浏览产品与版本 | 内置 |
| `product.create` | 新建产品 | 创建产品 | 内置 |
| `product.edit` | 编辑产品 | 修改产品信息 | 内置 |
| `product.version` | 版本管理 | 维护版本号与更新说明 | 内置 |
| `product.pricing` | 定价策略 | 设置价格与折扣 | 内置 |
| `product.delete` | 删除产品 | 删除产品（谨慎） | 内置 |
| `license.read` | 查看授权码 | 浏览授权码列表 | 内置 |
| `license.create` | 生成授权码 | 单个生成激活码 | 内置 |
| `license.batch` | 批量生成 | 按数量批量生成 | 内置 |
| `license.extend` | 延期 / 续期 | 调整有效期 | 内置 |
| `license.transfer` | 转移绑定 | 把授权码换绑到另一台机器 | 内置 |
| `license.revoke` | 吊销授权 | 立即失效某个授权码 | 内置 |
| `license.blacklist` | 黑名单 | 拉黑机器码或账号 | 内置 |
| `license.log` | 校验日志 | 查看授权校验记录 | 内置 |
| `device.read` | 查看跟随端 | 浏览已绑定的机器 | 内置 |
| `device.unbind` | 解绑机器 | 解除机器与授权码的绑定 | 内置 |
| `device.command` | 下发指令 | 远程停用或重启跟随端 | 内置 |
| `device.alarm` | 告警处理 | 处理掉线与异常告警 | 内置 |
| `device.export` | 导出机器 | 导出机器与绑定数据 | 内置 |

### 订单与支付（4）

| 权限标识 | 名称 | 说明 | 来源 |
|---|---|---|---|
| `order.read` | 查看订单 | 浏览会员的购买订单 | 内置 |
| `order.refund` | 订单退款 | 发起或确认退款 | 内置 |
| `order.export` | 导出订单 | 导出订单数据对账 | 内置 |
| `payment.config` | 支付配置 | 配置支付渠道与密钥 | 内置 |

### 审计（7）

| 权限标识 | 名称 | 说明 | 来源 |
|---|---|---|---|
| `audit.read` | 查看日志 | 浏览操作与授权日志 | 内置 |
| `audit.operation` | 操作日志 | 谁在什么时候改了什么 | 内置 |
| `audit.traffic` | 访问日志 | 访客与会员的访问记录、IP、停留时长 | 内置 |
| `audit.alert` | 告警规则 | 配置异常行为告警 | 内置 |
| `audit.report` | 统计报表 | 生成周期报表 | 内置 |
| `audit.export` | 导出日志 | 导出为文件 | 内置 |
| `audit.clean` | 清理日志 | 按时间清理历史日志 | 内置 |

### 统计（3）

| 权限标识 | 名称 | 说明 | 来源 |
|---|---|---|---|
| `stats.traffic` | 流量统计 | 每日访客与会员的访问量 | 内置 |
| `stats.page` | 页面停留 | 每个页面/区块的停留时长 | 内置 |
| `stats.export` | 导出统计 | 导出统计数据 | 内置 |

### 插件（5）

| 权限标识 | 名称 | 说明 | 来源 |
|---|---|---|---|
| `plugin.read` | 查看插件 | 浏览已安装插件 | 内置 |
| `plugin.toggle` | 启用 / 停用 | 开关插件 | 内置 |
| `plugin.config` | 插件配置 | 修改插件参数 | 内置 |
| `plugin.install` | 安装插件 | 安装新插件 | 内置 |
| `plugin.delete` | 卸载插件 | 卸载插件（谨慎） | 内置 |

### 密钥（3）

| 权限标识 | 名称 | 说明 | 来源 |
|---|---|---|---|
| `crypto.key.read` | 查看密钥 | 浏览加密密钥的元信息（不含密钥内容） | 内置 |
| `crypto.key.rotate` | 轮换密钥 | 生成新密钥并切换（**最高危**） | 内置 |
| `crypto.key.revoke` | 吊销密钥 | 吊销某个密钥版本 | 内置 |

### 系统（9）

| 权限标识 | 名称 | 说明 | 来源 |
|---|---|---|---|
| `system.admin` | 管理员设置 | 指定谁是管理员并分配权限 | 内置 |
| `system.role` | 身份权限 | 配置每种身份的默认权限 | 内置 |
| `system.param` | 系统参数 | 站点、授权、心跳等参数 | 内置 |
| `system.notify` | 通知渠道 | 邮件、短信、Webhook 配置 | 内置 |
| `system.api` | API 密钥 | 管理对外接口密钥 | 内置 |
| `system.webhook` | Webhook | 配置事件回调地址 | 内置 |
| `system.cache` | 缓存管理 | 刷新 CDN 与缓存 | 内置 |
| `system.backup` | 数据备份 | 备份与恢复 | 内置 |
| `system.danger` | 高危操作 | 数据清理、重置等不可逆操作 | 内置 |

## 四、权限 × 角色 矩阵

✅ = 该角色的**默认基线**里有它（个人还能另外加减，见 `user_permissions`）。

| 权限标识 | owner | admin | moderator | blogger | member |
|---|---|---|---|---|---|
| `user.read` | ✅ | ✅ | ✅ | | |
| `user.detail` | ✅ | ✅ | ✅ | | |
| `user.edit` | ✅ | ✅ | | | |
| `user.status` | ✅ | ✅ | | | |
| `user.mute` | ✅ | ✅ | ✅ | | |
| `user.ban` | ✅ | | ✅ | | |
| `user.role` | ✅ | | | | |
| `user.permission` | ✅ | | | | |
| `user.tag` | ✅ | ✅ | | | |
| `user.note` | ✅ | ✅ | ✅ | | |
| `user.import` | ✅ | | | | |
| `user.export` | ✅ | | | | |
| `user.delete` | ✅ | | | | |
| `blog.read` | ✅ | ✅ | ✅ | ✅ | |
| `blog.create` | ✅ | | | ✅ | |
| `blog.review` | ✅ | ✅ | | | |
| `blog.edit` | ✅ | | | ✅ | |
| `blog.publish` | ✅ | | | | |
| `blog.pin` | ✅ | | | | |
| `blog.feature` | ✅ | | | | |
| `blog.category` | ✅ | | | | |
| `blog.comment` | ✅ | | | | |
| `blog.delete` | ✅ | | | | |
| `article.read` | ✅ | ✅ | | | |
| `article.create` | ✅ | ✅ | | | |
| `article.edit` | ✅ | ✅ | | | |
| `article.preview` | ✅ | | | | |
| `article.publish` | ✅ | | | | |
| `article.schedule` | ✅ | | | | |
| `article.category` | ✅ | | | | |
| `article.revision` | ✅ | | | | |
| `article.seo` | ✅ | | | | |
| `article.delete` | ✅ | | | | |
| `notice.read` | ✅ | ✅ | | | |
| `notice.create` | ✅ | ✅ | | | |
| `notice.edit` | ✅ | | | | |
| `notice.publish` | ✅ | | | | |
| `notice.push` | ✅ | | | | |
| `notice.target` | ✅ | | | | |
| `notice.delete` | ✅ | | | | |
| `feedback.read` | ✅ | | | | |
| `feedback.reply` | ✅ | | | | |
| `feedback.handle` | ✅ | | | | |
| `feedback.close` | ✅ | | | | |
| `feedback.export` | ✅ | | | | |
| `feedback.delete` | ✅ | | | | |
| `forum.read` | ✅ | ✅ | ✅ | ✅ | |
| `forum.board.create` | ✅ | | | | |
| `forum.board.edit` | ✅ | | | | |
| `forum.board.delete` | ✅ | | | | |
| `forum.post.create` | ✅ | | | | |
| `forum.post.edit` | ✅ | | | | |
| `forum.post.pin` | ✅ | | ✅ | | |
| `forum.post.feature` | ✅ | | ✅ | | |
| `forum.post.highlight` | ✅ | | ✅ | | |
| `forum.post.move` | ✅ | | ✅ | | |
| `forum.post.lock` | ✅ | | ✅ | | |
| `forum.post.delete` | ✅ | ✅ | ✅ | | |
| `forum.reply.delete` | ✅ | ✅ | ✅ | | |
| `forum.report` | ✅ | ✅ | ✅ | | |
| `forum.user.mute` | ✅ | | ✅ | | |
| `forum.statistics` | ✅ | | | | |
| `media.read` | ✅ | | | | |
| `media.create` | ✅ | | | | |
| `media.material` | ✅ | | | | |
| `media.schedule` | ✅ | | | | |
| `media.publish` | ✅ | | | | |
| `media.channel` | ✅ | | | | |
| `media.comment` | ✅ | | | | |
| `media.statistics` | ✅ | | | | |
| `media.delete` | ✅ | | | | |
| `product.read` | ✅ | | | | |
| `product.create` | ✅ | | | | |
| `product.edit` | ✅ | | | | |
| `product.version` | ✅ | | | | |
| `product.pricing` | ✅ | | | | |
| `product.delete` | ✅ | | | | |
| `license.read` | ✅ | | | | |
| `license.create` | ✅ | | | | |
| `license.batch` | ✅ | | | | |
| `license.extend` | ✅ | | | | |
| `license.transfer` | ✅ | | | | |
| `license.revoke` | ✅ | | | | |
| `license.blacklist` | ✅ | | | | |
| `license.log` | ✅ | | | | |
| `device.read` | ✅ | | | | |
| `device.unbind` | ✅ | | | | |
| `device.command` | ✅ | | | | |
| `device.alarm` | ✅ | | | | |
| `device.export` | ✅ | | | | |
| `order.read` | ✅ | | | | |
| `order.refund` | ✅ | | | | |
| `order.export` | ✅ | | | | |
| `payment.config` | ✅ | | | | |
| `audit.read` | ✅ | ✅ | | | |
| `audit.operation` | ✅ | | | | |
| `audit.traffic` | ✅ | | | | |
| `audit.alert` | ✅ | | | | |
| `audit.report` | ✅ | | | | |
| `audit.export` | ✅ | | | | |
| `audit.clean` | ✅ | | | | |
| `stats.traffic` | ✅ | | | | |
| `stats.page` | ✅ | | | | |
| `stats.export` | ✅ | | | | |
| `plugin.read` | ✅ | | | | |
| `plugin.toggle` | ✅ | | | | |
| `plugin.config` | ✅ | | | | |
| `plugin.install` | ✅ | | | | |
| `plugin.delete` | ✅ | | | | |
| `crypto.key.read` | ✅ | | | | |
| `crypto.key.rotate` | ✅ | | | | |
| `crypto.key.revoke` | ✅ | | | | |
| `system.admin` | ✅ | | | | |
| `system.role` | ✅ | | | | |
| `system.param` | ✅ | | | | |
| `system.notify` | ✅ | | | | |
| `system.api` | ✅ | | | | |
| `system.webhook` | ✅ | | | | |
| `system.cache` | ✅ | | | | |
| `system.backup` | ✅ | | | | |
| `system.danger` | ✅ | | | | |
