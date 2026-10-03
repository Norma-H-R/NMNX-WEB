<?php

declare(strict_types=1);

/**
 * CryptoServiceProvider —— 加密模块的装配入口
 *
 * 本文件属于 core（纯 API 后端）的 Crypto 模块。
 * 用途：把 `Encryptor` 接口按 `config/crypto.php` 的 driver 绑定进容器。
 *       **这是全项目唯一一处决定"用哪个加密实现"的地方** ——
 *       业务代码只依赖接口，所以换实现只改这里的 match。
 * 谁在调：Laravel 通过 `bootstrap/providers.php` 自动加载。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/06-crypto.md
 */

namespace App\Modules\Crypto;

use App\Modules\Crypto\Contracts\Encryptor;
use App\Modules\Crypto\Encryptors\NullEncryptor;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

final class CryptoServiceProvider extends ServiceProvider
{
    /**
     * 注册容器绑定。
     *
     * 用法：
     *   // 业务侧（任意位置）：
     *   $enc = app(Encryptor::class);
     *   $cipher = $enc->encrypt('明文');
     *
     * 边界/注意：
     *   1. 用 `singleton` 而不是 `bind`：`KeyRing` 的构造会校验配置，
     *      一次请求里只想校验一次。
     *   2. 未知驱动**直接抛异常**，不要静默回退到 null ——
     *      生产上把 `CRYPTO_DRIVER` 拼错却悄悄变明文，是最坏的结果。
     *
     *
     * @throws InvalidArgumentException 当 config('crypto.driver') 是未支持的值时
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    实现真实算法后，在这里的 match 里加分支（如 'aes-gcm'）
     */
    public function register(): void
    {
        $this->app->singleton(Encryptor::class, function (): Encryptor {
            $driver = (string) config('crypto.driver', 'null');

            return match ($driver) {
                // 透传实现不需要密钥，所以**不构造 KeyRing** ——
                // KeyRing 会在构造时校验密钥表，一期尚未配 CRYPTO_SECRET，
                // 依赖它会导致启动即崩（详见 NullEncryptor 的构造函数注释）。
                'null' => new NullEncryptor((string) config('crypto.key_id', 'k1')),
                default => throw new InvalidArgumentException(
                    "未知的加密驱动 [{$driver}]。config/crypto.php 的 driver 目前只支持：null。"
                ),
            };
        });
    }

    /**
     * 启动阶段的动作。
     *
     * 用法：
     *   // 目前无需启动动作，保持空实现
     *
     * 边界/注意：
     *   当前**故意为空**。将来若需要注册 Eloquent Cast 或
     *   发布配置文件（`publishes`），在这里加。
     *
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    实现 Eloquent Cast `EncryptedString` 后，在这里注册它
     */
    public function boot(): void
    {
        //
    }
}
