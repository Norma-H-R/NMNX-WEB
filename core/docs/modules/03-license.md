# 模块 3 · License（授权内核）

> 文档版本：`0.1.0` · 最后更新：`2026-10-03`

## 状态

**未开始**。目前 `/api/v1/client` 是个空路由组。

## 职责

**授权业务规则本体**：

- 授权码（`license_key`）的有效性判定
- **产品维度解析**：同一个接口服务多个 EA，按 `product_code` 走不同规则
- **宽限期（`grace_until`）** —— 交易系统绝不能因为授权服务器抖动就断单
- 授权状态的缓存与失效
- 机器绑定（`machine_id`）与到期时间

**不负责**（这些是**模块 4 Client** 的事）：
- 签名校验、防重放、幂等、限流、响应签名
- HTTP 层的一切

> 这样拆的原因：以后换接入方式（比如加 WebSocket 心跳），业务规则一行都不用动。

## 对外接口

本模块**没有自己的 HTTP 路由**，由模块 4 Client 调用其服务类。

内部服务接口（规划）：

```php
// app/Modules/License/LicenseService.php
public function verify(VerifyContext $ctx): LicenseDecision;
```

`LicenseDecision` 需要包含（对应之前定下的设计点）：

| 字段 | 说明 |
|---|---|
| `active` | 是否有效 |
| `grace_until` | **宽限期截止**。哪怕校验失败，只要在宽限期内就继续放行 |
| `expires_at` | 授权到期时间 |
| `server_time` | 服务器时间，防客户端改时钟 |
| `edition` / `product_code` | 回显产品维度 |

## 驱动体系（模块化的真正落点）

**加一个新 EA = 新增一个 Driver 类，路由/控制器/中间件全都不动。**

```
app/Modules/License/Drivers/
├─ LicenseDriver.php        （接口）
├─ NmnxMasterDriver.php     （主控 EA）
├─ NmnxFollowerDriver.php   （跟随端 EA）
└─ DriverManager.php        （按 product_code 解析出对应 Driver）
```

| 类 | 职责 |
|---|---|
| `LicenseDriver`（接口） | 声明 `productCode(): string`、`rules(): array`、`verify(...)` 等 |
| `NmnxMasterDriver` / `NmnxFollowerDriver` | 各自产品的具体规则（有效期策略、允许的机器数、版本门槛…） |
| `DriverManager` | `for(string $productCode): LicenseDriver`，未知产品抛业务异常 |

**为什么不用"每个 EA 一个端点"**：那会导致 URL 爆炸、加 EA 要改代码发版、无法统一限流与审计。

## 数据表

**规划中**：

| 表 | 关键字段 |
|---|---|
| `licenses` | `id` `license_key`(唯一) `product_code` `edition` `machine_id` `status` `expires_at` `grace_days` `activated_at` |
| `license_activations` | 换机 / 多机时记录绑定历史（视规则再定是否要） |

## 缓存（Redis）

授权状态**必须**缓存，否则每 60~300s 一次的心跳会持续打 DB：

| 键 | 内容 | TTL |
|---|---|---|
| `nmnx:license:{license_key}` | 授权状态快照（含 `grace_until`） | 建议 60~300s，与心跳间隔同量级 |

后台改授权时**主动失效**该键。缓存里必须有 `grace_until`，这样即使 Redis 和 DB 同时不可用，EA 侧仍能按宽限期继续跑。

## 配置与环境变量

| 配置 | 说明 |
|---|---|
| `CACHE_STORE=redis` | 已配好 |
| `CACHE_PREFIX=nmnx` | 已配好，避免与其他项目冲突 |
| 宽限期天数 | 建议按产品维度放在 Driver 里，而不是全局常量 |

## 待办

- [ ] 定义 `LicenseDriver` 接口（先把「一个 Driver 需要回答哪些问题」想清楚）
- [ ] 定义 `LicenseDecision` 与 `VerifyContext` 数据结构
- [ ] `DriverManager` + 两个 Driver 的最小实现
- [ ] `licenses` 迁移与模型
- [ ] `LicenseService::verify()` + Redis 缓存读写与失效
- [ ] 宽限期逻辑的单元测试（**这条最重要**：掉线/过期/宽限内的边界）
