<?php

declare(strict_types=1);

/**
 * NullEncryptor —— 一期默认实现：原样透传，不做任何加解密
 *
 * 本文件属于 core（纯 API 后端）的 Crypto 模块。
 * 用途：让上层业务**现在就可以按"有加密"来写**（依赖 Encryptor 接口），
 *       但实际不发生加解密。后期换真实实现时，业务代码一行都不用改。
 * 谁在调：CryptoServiceProvider 按 `CRYPTO_DRIVER=null` 绑定进容器。
 *
 * ⚠️ 安全提醒：
 *   走这个实现时数据是**明文**。`isEnabled()` 恒为 false，调用方据此可以
 *   在日志/出参里正确标注状态，**不要把明文当密文**。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/06-crypto.md
 */

namespace App\Modules\Crypto\Encryptors;

use App\Modules\Crypto\Contracts\Encryptor;

final class NullEncryptor implements Encryptor
{
    /**
     * 构造。
     *
     * 用法：
     *   new NullEncryptor('k1');
     *
     * 边界/注意：
     *   本实现**刻意不持有 KeyRing**。透传不需要密钥，而 KeyRing 构造时会校验
     *   "当前 key_id 必须在密钥表里" —— 若在这里依赖它，一期（尚未配 CRYPTO_SECRET）
     *   就会启动即崩，逼着大家往 .env 里塞一把假密钥，反而更危险。
     *   真实驱动才需要 KeyRing，由它自己去校验。
     *
     * @param  string  $keyId  当前密钥标识，仅用于回显，保证协议里的 key_id 字段
     *                         不因驱动而变
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function __construct(private readonly string $keyId) {}

    /**
     * 取当前密钥标识。
     *
     * 用法：
     *   app(Encryptor::class)->keyId(); // 'k1'
     *
     * @return string 密钥标识（构造时由 config('crypto.key_id') 传入）
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function keyId(): string
    {
        return $this->keyId;
    }

    /**
     * 当前是否真正加密。
     *
     * 用法：
     *   app(Encryptor::class)->isEnabled(); // false —— 一期恒为 false
     *
     * @return bool 恒为 false
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function isEnabled(): bool
    {
        return false;
    }

    /**
     * "加密"：一期原样返回。
     *
     * 用法：
     *   app(Encryptor::class)->encrypt('abc'); // 'abc'
     *
     * 边界/注意：
     *   输出与输入完全相同，所以**无法从值本身判断有没有加密过**。
     *   需要判断请用 `isEnabled()`，不要靠猜字符串形态。
     *
     * @param  string  $plain  明文
     * @return string 原样返回
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    换成真实算法实现
     */
    public function encrypt(string $plain): string
    {
        return $plain;
    }

    /**
     * "解密"：一期原样返回。
     *
     * 用法：
     *   app(Encryptor::class)->decrypt('abc'); // 'abc'
     *
     * @param  string  $payload  密文（一期即明文）
     * @return string 原样返回
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    换成真实算法实现 + 完整性校验
     */
    public function decrypt(string $payload): string
    {
        return $payload;
    }
}
