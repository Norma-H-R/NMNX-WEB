<?php

declare(strict_types=1);

/**
 * TestCase —— 测试基类（所有测试的根）
 *
 * 本文件属于 core（纯 API 后端）的测试基础设施。
 * 用途：只负责把 Laravel 的测试基类接进来。**API 断言请用 `Tests\ApiTestCase`**
 *       （`tests/ApiTestCase.php`），那里才有统一响应体的断言方法。
 * 谁在调：`tests/Unit`、`tests/Feature` 下的所有测试类，直接或间接继承它。
 *
 * @version 0.2.0
 *
 * @since   2026-10-03
 * @see     docs/standards.md （第 4 节：提交前自检清单要求"实际验证过"）
 */

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * 测试开始前的准备动作。
     *
     * 用法：
     *   // 子类覆盖时必须调 parent::setUp()，否则框架不启动
     *   protected function setUp(): void { parent::setUp(); ... }
     *
     * 边界/注意：
     *   Laravel 的 `CreatesApplication` 已经并进基类了，这里**不需要**再写 `createApplication()`。
     *   当前刻意为空 —— 不要在这里塞全局假数据，那会让单个测试的依赖变得不可见。
     *
     *
     * @version 0.2.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    protected function setUp(): void
    {
        parent::setUp();
    }
}
