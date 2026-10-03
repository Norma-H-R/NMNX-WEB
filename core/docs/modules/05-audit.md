# 模块 5 · Audit（审计日志）

> 文档版本：`0.1.0` · 最后更新：`2026-10-03`

## 状态

**未开始**。

## 职责

**全量**记录每一次值得追溯的操作：谁 / 什么时候 / 哪台机器 / 请求了什么 / 结果如何 / 来自哪个 IP。

覆盖三类来源：

| 来源 | 记什么 |
|---|---|
| EA 客户端校验（模块 4） | 授权码、`machine_id`、结果、`grace_until` |
| 管理端操作（模块 1/2） | 登录、发布/修改/删除内容、改授权 |
| 系统事件 | 队列失败、异常、授权批量导入导出 |

**不负责**：业务判断（它只记录，不拦截）；日志的展示 UI（那是 `admin/` 前端）。

## 对外接口

写入不是 HTTP 接口，由其他模块内部调用。查询接口：

| 方法 | 路径 | 鉴权 | 说明 | 状态 |
|---|---|---|---|---|
| GET | `/api/v1/admin/audit-logs` | 令牌 | 分页查询，支持按时间/来源/结果/`license_key`/`machine_id` 筛选 | ⛔ 待做 |

## 关键类与函数

**规划中**：

| 计划位置 | 用途 |
|---|---|
| `app/Modules/Audit/Models/AuditLog.php` | 模型 |
| `app/Modules/Audit/AuditLogger.php` | 统一写入入口：`record(AuditEvent $e)` |
| `app/Modules/Audit/AuditEvent.php` | 事件值对象（来源、动作、主体、结果、上下文） |
| `app/Modules/Audit/Http/Controllers/AuditLogController.php` | 查询接口 |

调用方式应当是**一行**，不要在每个控制器里手搓数组：

```php
Audit::record(AuditEvent::forLicense($licenseKey, 'verify', $result, $context));
```

写入走**队列**（`QUEUE_CONNECTION=database`），避免拖慢 EA 心跳接口 —— 但要注意：
**校验接口必须在 1~2 秒内返回**，所以审计不能阻塞在关键路径上。

## 数据表

**规划中**：

| 表 | 关键字段 |
|---|---|
| `audit_logs` | `id` `source`(ea/admin/system) `action` `actor_type` `actor_id` `license_key` `machine_id` `result`(ok/denied/error) `message` `ip` `user_agent` `request_id` `context`(JSON) `created_at` |

索引建议：`created_at`、`license_key`、`machine_id`、(`source`, `action`)。

## 配置与环境变量

| 配置 | 说明 |
|---|---|
| `QUEUE_CONNECTION=database` | 已配好；审计用队列异步写 |
| 保留期 | 待定（例如 EA 校验日志 180 天，管理端操作永久） |
| 脱敏规则 | **待定**：`sign` 是否记录（有助排查，但属敏感信息）；密码类字段**绝不**记录 |

## 待办

- [ ] 定 `AuditEvent` 的字段集（先想清楚要能回答哪些问题，再建表）
- [ ] 迁移 + 模型 + 索引
- [ ] `AuditLogger`（**只用一行调用**）+ 队列写入
- [ ] 在 EA 校验链路里接入（注意：不能拖慢关键路径）
- [ ] 管理端登录与内容操作接入
- [ ] 查询接口 + 分页筛选
- [ ] 定保留期与清理任务（`php artisan schedule`）
