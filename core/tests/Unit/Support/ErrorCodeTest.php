<?php

declare(strict_types=1);

/**
 * ErrorCodeTest —— 错误码表的自洽性检查
 *
 * 本文件属于 core（纯 API 后端）的 Support 模块测试。
 * 用途：**用反射把"表本身"钉死** —— 以后加错误码常量却忘了加映射，这里会直接红。
 *       这类"忘了登记"的问题不会在功能测试里暴露（因为那条分支根本没被走到），
 *       只有遍历常量才能抓到。
 * 谁在调：`php artisan test`。
 *
 * 边界/注意：本测试不启动框架（`ErrorCode` 是纯静态类），所以直接继承 PHPUnit 的
 * 基类而不是 `Tests\TestCase` —— 快得多，也不会因为某个 Provider 挂掉而连带失败。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/00-support.md
 */

namespace Tests\Unit\Support;

use App\Modules\Support\ErrorCode;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ErrorCodeTest extends TestCase
{
    /** 场景：每个公开的错误码常量都登记了 HTTP 状态码映射（加了常量忘了登记 = 红） */
    public function test_every_public_code_is_registered(): void
    {
        $reflection = new ReflectionClass(ErrorCode::class);
        $missing = [];
        $checked = 0;

        foreach ($reflection->getReflectionConstants() as $constant) {
            if ($constant->isPrivate()) {
                continue;
            }

            $checked++;

            /** @var string $code */
            $code = $constant->getValue();

            if (! ErrorCode::has($code)) {
                $missing[] = $constant->getName().' => '.$code;
            }
        }

        $this->assertGreaterThan(0, $checked, '没有扫到任何公开错误码常量，反射逻辑可能失效了');
        $this->assertSame([], $missing, '以下错误码没有登记到 HTTP_STATUS 映射表：'.\implode(', ', $missing));
    }

    /** 场景：除 OK 外，每个错误码的 HTTP 状态码都落在 4xx / 5xx 区间（防止误映射成 200） */
    public function test_every_code_maps_to_an_error_status(): void
    {
        $reflection = new ReflectionClass(ErrorCode::class);
        $wrong = [];

        foreach ($reflection->getReflectionConstants() as $constant) {
            if ($constant->isPrivate()) {
                continue;
            }

            /** @var string $code */
            $code = $constant->getValue();

            if ($code === ErrorCode::OK) {
                $this->assertSame(200, ErrorCode::httpStatus($code));

                continue;
            }

            $status = ErrorCode::httpStatus($code);

            if ($status < 400 || $status > 599) {
                $wrong[] = $constant->getName().' => '.$status;
            }
        }

        $this->assertSame([], $wrong, '以下错误码映射到了非错误状态码：'.\implode(', ', $wrong));
    }

    /** 场景：未登记的错误码按 500 处理，不静默返回 200 */
    public function test_unregistered_code_falls_back_to_500(): void
    {
        $this->assertSame(500, ErrorCode::httpStatus('NOT_REGISTERED_CODE'));
        $this->assertFalse(ErrorCode::has('NOT_REGISTERED_CODE'));
    }

    /** 场景：未登记的错误码有兜底文案，取文案时不抛异常 */
    public function test_unregistered_code_has_fallback_message(): void
    {
        $this->assertSame('请求失败', ErrorCode::message('NOT_REGISTERED_CODE'));
    }

    /** 场景：几个关键错误码的状态码与文案符合约定（钉住对外协议） */
    public function test_key_codes_are_stable(): void
    {
        $this->assertSame(200, ErrorCode::httpStatus(ErrorCode::OK));
        $this->assertSame(401, ErrorCode::httpStatus(ErrorCode::SYS_UNAUTHENTICATED));
        $this->assertSame(403, ErrorCode::httpStatus(ErrorCode::LICENSE_EXPIRED));
        $this->assertSame(422, ErrorCode::httpStatus(ErrorCode::SYS_VALIDATION));
        $this->assertSame('授权已过期', ErrorCode::message(ErrorCode::LICENSE_EXPIRED));
    }
}
