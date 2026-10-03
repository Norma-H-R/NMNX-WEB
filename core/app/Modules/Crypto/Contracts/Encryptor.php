<?php

declare(strict_types=1);

/**
 * Encryptor —— 加解密契约
 *
 * 本文件属于 core（纯 API 后端）的 Crypto 模块。
 * 用途：**所有模块加解密只依赖这个接口，不许直接调用算法或 `openssl_*` 函数。**
 *       这样一期先用 NullEncryptor（原样透传）跑通业务，后期换成真算法时
 *       业务代码一行都不用改。
 * 谁在调：各模块的 Service、Eloquent Cast（`EncryptedString`，待实现）。
 *
 * 一期状态（2026-10-03 已确认）：
 *   **只留接口，不实现任何算法。** 算法怎么定、怎么协商，后期再实现。
 *   见 docs/requirements.md 的 C-7。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/06-crypto.md
 */

namespace App\Modules\Crypto\Contracts;

interface Encryptor
{
    /**
     * 当前使用的密钥标识。
     *
     * 用法：
     *   $enc = app(Encryptor::class);
     *   $keyId = $enc->keyId();          // 例：'k1'
     *   // 出参里带上它，客户端才知道该用哪把密钥解（多密钥并存时必需）
     *
     * 边界/注意：
     *   这个值**必须**出现在请求/响应协议里（字段名待定，见模块文档待办）。
     *   一期只有一把密钥，但字段现在就要留 —— 密钥一旦发出去就改不动了。
     *
     * @return string 密钥标识
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    与 Client 模块一起定下协议字段名（`key_id`？）
     */
    public function keyId(): string;

    /**
     * 当前加密是否真正生效。
     *
     * 用法：
     *   if (! app(Encryptor::class)->isEnabled()) {
     *       // 一期走这里：提醒"当前是明文"，别在日志里误称已加密
     *   }
     *
     * 边界/注意：
     *   一期 NullEncryptor 恒返回 `false`。它存在的意义是让调用方**能区分**
     *   "透传"和"真的加密了"，避免把明文当密文处理。
     *
     * @return bool 真正加密中返回 true
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function isEnabled(): bool;

    /**
     * 加密一段明文字符串。
     *
     * 用法：
     *   $payload = app(Encryptor::class)->encrypt('secret-value');
     *
     * 边界/注意：
     *   1. 入参是**字符串**，不是任意数组。需要加密多个字段就逐个调用，
     *      或者由调用方 json_encode 后整体加密 —— 两种做法的取舍见模块文档。
     *   2. 返回值的编码形态由实现决定（一期原样返回明文，后期可能是 base64）。
     *      **调用方不要假设它的具体格式**，只管原样存/传、再交给 decrypt()。
     *
     * @param  string  $plain  明文
     * @return string 密文（一期为原样明文）
     *
     * @throws \RuntimeException 密钥缺失或算法不可用时
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    实现真实算法（AES-GCM 或字段级方案，待定）
     */
    public function encrypt(string $plain): string;

    /**
     * 解密一段密文字符串。
     *
     * 用法：
     *   $plain = app(Encryptor::class)->decrypt($payload);
     *
     * 边界/注意：
     *   1. **只接受本 Encryptor 自己产出的密文**。跨实现（一期明文 → 后期密文）
     *      的历史数据需要迁移脚本，不要指望 decrypt() 自动兼容。
     *   2. 解不出来要抛异常，**不要静默返回空串** —— 那会让脏数据一路流到业务层。
     *
     * @param  string  $payload  密文
     * @return string 明文
     *
     * @throws \RuntimeException 密钥缺失、密文损坏或校验失败时
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    实现真实算法 + 完整性校验（防篡改）
     */
    public function decrypt(string $payload): string;
}
