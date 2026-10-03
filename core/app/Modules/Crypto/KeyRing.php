<?php

declare(strict_types=1);

/**
 * KeyRing —— 密钥环（支持多密钥并存）
 *
 * 本文件属于 core（纯 API 后端）的 Crypto 模块。
 * 用途：按 `key_id` 取密钥。**一期只有一把密钥，但结构上支持多把** ——
 *       这是为了密钥轮换（requirements.md 的 B-5）：如果只支持"当前密钥"，
 *       轮换的那一刻所有在用的 EA 和前端会同时 401。
 * 谁在调：Encryptor 实现类。
 *
 * 轮换将来怎么走（现在不实现，但结构已留好）：
 *   1. 在配置里追加新 key（`crypto.keys` 里多一条），旧 key 保留
 *   2. 把 `crypto.key_id` 指向新 key
 *   3. 老客户端仍带旧 key_id 请求 → `byId()` 能取到旧密钥 → 继续可用
 *   4. 观察期结束后，再从配置里摘掉旧 key
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/06-crypto.md
 */

namespace App\Modules\Crypto;

use InvalidArgumentException;

final class KeyRing
{
    /**
     * 构造。
     *
     * 用法：
     *   new KeyRing(['k1' => 'secret-a'], 'k1');
     *   new KeyRing(['k1' => 'old', 'k2' => 'new'], 'k2');   // 轮换中：新旧并存
     *
     * 边界/注意：
     *   1. `$currentKeyId` 必须存在于 `$keys` 里，否则构造即失败 ——
     *      配置错误要在启动时就炸，不要等到某个请求才炸。
     *   2. 密钥从 `config/crypto.php` 读入，不要硬编码在代码里。
     *
     * @param  array<string, string>  $keys  密钥表：key_id => 密钥内容
     * @param  string  $currentKeyId  当前用于加密的 key_id
     *
     * @throws InvalidArgumentException 当 $currentKeyId 不在 $keys 中时
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function __construct(
        private readonly array $keys,
        private readonly string $currentKeyId,
    ) {
        if (! isset($this->keys[$this->currentKeyId])) {
            throw new InvalidArgumentException(
                "密钥环配置错误：当前密钥标识 [{$this->currentKeyId}] 不在密钥表里。"
            );
        }
    }

    /**
     * 从 Laravel 配置构造。
     *
     * 用法：
     *   KeyRing::fromConfig();   // 读 config('crypto.*')
     *
     * 边界/注意：
     *   若配置里写了单个 `crypto.secret` 且它的 key_id 还不在 `crypto.keys` 里，
     *   会自动补进去 —— 这样 `.env` 只写一把密钥也能跑（一期的常态）。
     *
     * @return self 密钥环实例
     *
     * @throws InvalidArgumentException 配置不完整（见构造函数）
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public static function fromConfig(): self
    {
        /** @var array<string, string> $keys */
        $keys = (array) config('crypto.keys', []);

        $current = (string) config('crypto.key_id', 'k1');
        $secret = config('crypto.secret');

        if (is_string($secret) && $secret !== '' && ! isset($keys[$current])) {
            $keys[$current] = $secret;
        }

        return new self($keys, $current);
    }

    /**
     * 取当前用于加密的 key_id。
     *
     * 用法：
     *   KeyRing::fromConfig()->currentKeyId(); // 'k1'
     *
     * @return string 当前 key_id
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function currentKeyId(): string
    {
        return $this->currentKeyId;
    }

    /**
     * 判断某个 key_id 是否存在（解密时用旧 id 也应当命中）。
     *
     * 用法：
     *   $ring->has('k0'); // 轮换中仍支持的老密钥
     *
     * @param  string  $keyId  待检查的 key_id
     * @return bool 存在返回 true
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function has(string $keyId): bool
    {
        return isset($this->keys[$keyId]);
    }

    /**
     * 按 key_id 取密钥。
     *
     * 用法：
     *   $ring->secret('k1');
     *
     * 边界/注意：
     *   取不到时抛 `InvalidArgumentException`（配置/协议错误），
     *   **不要**返回空串 —— 那会让上层拿空密钥去解密，报出更难查的错。
     *
     * @param  string  $keyId  密钥标识
     * @return string 密钥内容
     *
     * @throws InvalidArgumentException 该 key_id 未登记时
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function secret(string $keyId): string
    {
        if (! $this->has($keyId)) {
            throw new InvalidArgumentException("密钥环里没有登记密钥标识 [{$keyId}]。");
        }

        return $this->keys[$keyId];
    }

    /**
     * 列出所有已登记的 key_id（不含密钥内容，可安全写日志）。
     *
     * 用法：
     *   $ring->keyIds(); // ['k1', 'k2']
     *
     * 边界/注意：
     *   返回的是 **id 而不是密钥**，就是为了能安全地打日志排查轮换问题。
     *
     * @return list<string> key_id 列表
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function keyIds(): array
    {
        return array_keys($this->keys);
    }
}
