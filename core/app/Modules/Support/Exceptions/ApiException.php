<?php

declare(strict_types=1);

/**
 * ApiException —— 带错误码的业务异常
 *
 * 本文件属于 core（纯 API 后端）的 Support 模块。
 * 用途：业务规则失败时抛它，**全局异常处理器会把它转成统一响应体**
 *       （见 bootstrap/app.php 的 `$exceptions->render(ApiException ...)`），
 *       所以 Service / Driver 层不用关心 HTTP，也不用拼响应数组。
 * 谁在调：各模块的 Service / Driver / Request。
 *
 * 与 HTTP 的关系：
 *   异常只携带**错误码**，HTTP 状态码由 `ErrorCode::httpStatus()` 反查得到 ——
 *   对应关系只在 ErrorCode 一处维护。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/00-support.md
 */

namespace App\Modules\Support\Exceptions;

use App\Modules\Support\ErrorCode;
use RuntimeException;
use Throwable;

final class ApiException extends RuntimeException
{
    /**
     * 业务错误码（字符串）。注意：**不要**和 `Exception::getCode()` 混用 ——
     * 父类的 code 是 int 且这里恒为 0，取业务码请用 {@see self::errorCode()}。
     */
    private readonly string $errorCode;

    /** 错误上下文，会被放进统一响应体的 `meta`。 */
    private readonly array $meta;

    /**
     * 构造业务异常。
     *
     * 用法：
     *   throw new ApiException(ErrorCode::LICENSE_EXPIRED);
     *   throw new ApiException(ErrorCode::LICENSE_MACHINE_MISMATCH, null, ['machine_id' => $id]);
     *   throw new ApiException(ErrorCode::SYS_FORBIDDEN, '这个操作需要管理员权限');
     *
     * 边界/注意：
     *   1. `$message` 留 null 时取 `ErrorCode::message($errorCode)` 的默认文案，
     *      这样不必在每个 throw 点重复写同一句话。
     *   2. `$errorCode` 必须是 `ErrorCode` 里已登记的常量；未登记的会得到 500
     *      （刻意如此：防止漏登记却表现为成功）。
     *   3. `$meta` 会原样返回给客户端，**不要放密钥、内部路径这类敏感信息**。
     *
     * @param  string  $errorCode  `ErrorCode` 常量
     * @param  string|null  $message  覆盖默认文案，null 用默认
     * @param  array<string, mixed>  $meta  错误上下文，输出到响应体的 meta
     * @param  Throwable|null  $previous  上一个异常，用于保留堆栈
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function __construct(
        string $errorCode,
        ?string $message = null,
        array $meta = [],
        ?Throwable $previous = null,
    ) {
        $this->errorCode = $errorCode;
        $this->meta = $meta;

        parent::__construct($message ?? ErrorCode::message($errorCode), 0, $previous);
    }

    /**
     * 取业务错误码。
     *
     * 用法：
     *   catch (ApiException $e) { logger()->warning($e->errorCode()); }
     *
     * @return string `ErrorCode` 常量
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * 取错误上下文。
     *
     * 用法：
     *   throw new ApiException(ErrorCode::SYS_VALIDATION, null, ['errors' => $errors]);
     *
     * 边界/注意：
     *   调用方拿到的是数组副本，修改它不会影响异常自身（属性为 readonly）。
     *
     * @return array<string, mixed> 会被放进响应体 meta
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * 取该错误码对应的 HTTP 状态码。
     *
     * 用法：
     *   $e->httpStatus(); // 例：LICENSE_EXPIRED -> 403
     *
     * @return int HTTP 状态码
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function httpStatus(): int
    {
        return ErrorCode::httpStatus($this->errorCode);
    }
}
