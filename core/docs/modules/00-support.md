# 模块 0 · Support（公共层）

> 文档版本：`0.2.0` · 最后更新：`2026-10-03`

## 状态

**已实现（一期完成）**。统一响应体、错误码、异常转 JSON 三件套已落地并实测通过。

## 职责

- 定义**统一的 API 响应体**（四字段结构），所有模块的出参都按它返回
- 定义**错误码**，给前端与 EA 客户端一套稳定的机器可读标识
- 把框架抛出的异常统一转成上面那套结构（不返回 HTML、不重定向）
- 提供探针接口

**不负责**：任何业务逻辑。**本模块不依赖任何其他模块**，且没有 ServiceProvider ——
全是静态方法，没有容器绑定需求。

## 响应体结构（固定四字段）

```json
{
  "code":    "OK",
  "message": "成功",
  "data":    { "id": 1 },
  "meta":    {}
}
```

| 字段 | 说明 |
|---|---|
| `code` | **机器可读**，见 `ErrorCode`。**客户端按它分支** |
| `message` | 人读文案，将来国际化。**客户端不要拿它做判断** |
| `data` | 成功时为业务数据；失败时恒为 `null`。为空时输出 `{}`（不是 `[]`），客户端可无条件按对象取字段 |
| `meta` | 附加信息：分页、错误上下文。**永远是对象**，为空时输出 `{}` |

中文不做 `\uXXXX` 转义（`JSON_UNESCAPED_UNICODE`），字节数更小、日志可读。

## 对外接口

| 方法 | 路径 | 鉴权 | 说明 | 状态 |
|---|---|---|---|---|
| GET | `/api/v1/health` | 无 | API 探针，返回服务名与**服务器时间**（EA 侧对时用） | ✅ |
| GET | `/up` | 无 | Laravel 内置健康检查 | ✅ |
| GET | `/` | 无 | 根探针（web 层，纯 JSON 无视图） | ✅ |

## 关键类与函数

| 位置 | 类 / 方法 | 说明 |
|---|---|---|
| `app/Modules/Support/Http/ApiResponse.php` | `ApiResponse::ok(array $data = [], array $meta = []): JsonResponse` | 成功响应，HTTP 200 |
| 同上 | `ApiResponse::fail(string $code, array $meta = [], ?string $message = null): JsonResponse` | 失败响应，**状态码由错误码反查**，不在这里传 |
| 同上 | `ApiResponse::JSON_OPTIONS`（private const） | `JSON_UNESCAPED_UNICODE \| JSON_UNESCAPED_SLASHES` |
| `app/Modules/Support/ErrorCode.php` | `ErrorCode::httpStatus(string $code): int` | 错误码 → HTTP 状态码；未登记一律 **500**（刻意） |
| 同上 | `ErrorCode::message(string $code): string` | 错误码 → 默认中文文案（兜底用） |
| 同上 | `ErrorCode::has(string $code): bool` | 是否已登记 |
| `app/Modules/Support/Exceptions/ApiException.php` | `new ApiException(string $errorCode, ?string $message = null, array $meta = [], ?Throwable $previous = null)` | 业务异常。消息留 null 时取默认文案 |
| 同上 | `->errorCode(): string` / `->meta(): array` / `->httpStatus(): int` | 取业务码 / 上下文 / 对应状态码 |

### 异常 → 响应体的映射（在 `bootstrap/app.php`）

| 抛出的异常 | 转成 | HTTP |
|---|---|---|
| `ApiException` | 它自己带的错误码 | 由 `ErrorCode::httpStatus()` 反查 |
| `ValidationException` | `SYS_VALIDATION`，明细在 `meta.errors` | 422 |
| `AuthenticationException` | `SYS_UNAUTHENTICATED` | 401 |
| `NotFoundHttpException` | `SYS_NOT_FOUND` | 404 |
| 其它 `HttpExceptionInterface` | 405→`SYS_METHOD_NOT_ALLOWED`、429→`SYS_RATE_LIMITED`、403→`SYS_FORBIDDEN`，其余→`SYS_INTERNAL` | 原状态码 |

### 已登记的错误码

系统级 `SYS_*`（INTERNAL / VALIDATION / NOT_FOUND / METHOD_NOT_ALLOWED / UNAUTHENTICATED / FORBIDDEN / RATE_LIMITED）、
鉴权 `AUTH_*`、授权 `LICENSE_*`、EA 接入 `CLIENT_*`、内容 `CONTENT_NOT_FOUND`。
**新增错误码只改 `ErrorCode.php`**（加常量 + 两条映射），不要在各模块拼字符串。

## 数据表

无。

## 配置与环境变量

| 变量 | 作用 |
|---|---|
| `CORS_ALLOWED_ORIGINS` | 跨域白名单，见 `config/cors.php` |
| `APP_DEBUG` | 开发期 `true`；**上线必须关**，否则 500/404 会把堆栈与文件绝对路径返回给客户端 |

## 实测（2026-10-03）

```
GET  /api/v1/health        200  {"code":"OK","message":"成功","data":{"service":"nmnx-core",...},"meta":{}}
GET  /api/v1/public/ping   200  {"code":"OK","message":"成功","data":{"guard":"public"},"meta":{}}
GET  /api/v1/admin/me      401  {"code":"SYS_UNAUTHENTICATED","message":"未登录或令牌无效","data":null,"meta":{}}
GET  /api/v1/nope          404  {"code":"SYS_NOT_FOUND","message":"请求的资源不存在","data":null,"meta":{}}
DELETE /api/v1/health      405  {"code":"SYS_METHOD_NOT_ALLOWED",...}
```

## 待办

- [ ] `meta.request_id`：请求追踪 ID，便于和审计日志对齐
- [ ] 错误码的 `message` 走 `lang/`（国际化，按 `Accept-Language`）
- [ ] 分页信息的统一约定（`meta.page` / `meta.total` 的字段名定死）
