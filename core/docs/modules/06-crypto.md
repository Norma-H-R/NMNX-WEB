# 模块 06 · 加密（Crypto）

> 文档版本：`0.2.0` · 最后更新：`2026-10-03`

## 状态

**接口层已实现（一期完成）**。`Encryptor` 契约、透传实现、密钥环已落地并实测通过。
**算法尚未实现** —— 这是刻意的，见下。

## 职责

- 定义**加解密契约**（`Encryptor`），所有模块只依赖它，**不许直接调 `openssl_*`**
- **密钥管理**：多密钥并存结构（每把密钥带 `key_id`），一期只有一把
- 规范**浏览器端本地存储的加密**（R32 / D4）

**不负责**：

- HTTPS / TLS —— 那是传输层
- 一期**不实现具体算法**。算法怎么定、怎么协商，后期再填（requirements.md 的 C-7）

## 为什么一期"只留接口"

用户原话大意：**现在真正的复杂度不在加密算法上，而在"EA 初始化时那条请求的接口形状"上。**
字段一旦发出去，别人机器上装好的 EA 就改不动了 —— **字段要一次设计到位，算法可以后补**。

原先担心的"EA 侧 MQL 没有 AES"因此**暂时不存在**：一期根本不要求 EA 做加解密。

## 关键类与函数

| 位置 | 类 / 方法 | 说明 |
|---|---|---|
| `app/Modules/Crypto/Contracts/Encryptor.php` | `keyId(): string` | 当前密钥标识。**必须出现在协议里**，客户端据此选密钥 |
| 同上 | `isEnabled(): bool` | 是否真正加密中。一期恒 `false` —— 让调用方**能区分**"透传"与"已加密" |
| 同上 | `encrypt(string $plain): string` | 加密 |
| 同上 | `decrypt(string $payload): string` | 解密。**解不出来要抛异常，不要静默返回空串** |
| `app/Modules/Crypto/Encryptors/NullEncryptor.php` | 实现 `Encryptor` | 一期默认：原样透传，不做任何处理 |
| `app/Modules/Crypto/KeyRing.php` | `new KeyRing(array $keys, string $currentKeyId)` | 密钥环。**构造即校验**：`currentKeyId` 不在 `keys` 里直接抛错 |
| 同上 | `KeyRing::fromConfig(): self` | 从 `config/crypto.php` 构造 |
| 同上 | `->currentKeyId()` / `->has($id)` / `->secret($id)` / `->keyIds()` | 取当前 id / 是否登记 / 取密钥 / 列 id（**列 id 可安全打日志**） |
| `app/Modules/Crypto/CryptoServiceProvider.php` | `register()` | **全项目唯一**决定"用哪个加密实现"的地方（按 `config('crypto.driver')` 的 match） |

### 一个刻意的设计：NullEncryptor 不持有 KeyRing

`KeyRing` 构造时会校验"当前 key_id 必须在密钥表里"。一期尚未配 `CRYPTO_SECRET`，
如果让 `NullEncryptor` 依赖 `KeyRing`，就会**启动即崩**，逼着大家往 `.env` 里塞一把假密钥 ——
反而更危险（假密钥可能被当成真配置带到生产）。

所以：**透传实现只接一个 `keyId` 字符串**（用于回显保证协议一致），
`KeyRing` 留给真实驱动使用。校验职责跟着"谁真的需要密钥"走。

## 数据表

第一版**无**。后期若密钥要入库管理，再加 `crypto_keys`（`id` `key_id` `algorithm` `secret` `rotated_at`）。

## 配置与环境变量

见 `config/crypto.php`：

| 配置 | 一期值 | 说明 |
|---|---|---|
| `CRYPTO_DRIVER` | `null` | 目前只支持 `null`。**写错不会静默回退，直接抛异常**（避免拼错却悄悄变明文） |
| `CRYPTO_KEY_ID` | `k1` | **一期就要写进配置**，密钥标识一旦随协议发出去就改不动了（B-5） |
| `CRYPTO_SECRET` | 未设 | 一期用不到；配真实驱动时必须设，否则 `KeyRing` 构造失败 |
| `crypto.keys` | `[]` | 多密钥并存，为轮换预留 |

### 将来轮换怎么走（结构已留好）

1. 配置里追加新 key（`crypto.keys` 多一条），旧 key 保留
2. `CRYPTO_KEY_ID` 指向新 key
3. 老客户端仍带旧 `key_id` 请求 → `KeyRing::secret($oldId)` 仍能取到 → 继续可用
4. 观察期结束后再从配置摘掉旧 key

只支持"当前密钥"的话，轮换那一刻所有在用的 EA 和前端会**同时 401**。

## 实测（2026-10-03）

```
绑定的实现      = App\Modules\Crypto\Encryptors\NullEncryptor
keyId()         = k1
isEnabled()     = false
加密往返        = '秘密明文'          （透传，值不变）
KeyRing keyIds  = k1, k2             （多密钥并存可用）
has(k1)         = true               （轮换中旧 key 仍可取）
未登记 key 构造 = 正确抛 InvalidArgumentException
secret(未登记)  = 正确抛 InvalidArgumentException
```

## 待办

- [ ] 定下请求/响应里加密字段的**具体名字**（`key_id`? `enc`?），**必须与 Client 模块一起定**
- [ ] 定"哪些字段要加密"的清单（授权码 / 邮箱 / 手机号？），并入各模块文档
- [ ] `EncryptedString` Eloquent Cast（在 `CryptoServiceProvider::boot()` 里注册）
- [ ] 与前端约定**本地存储加密**方案（R32 / D4）
- [ ] 后期：定算法与协商方式，在 Provider 的 match 里加分支替换 `NullEncryptor`
