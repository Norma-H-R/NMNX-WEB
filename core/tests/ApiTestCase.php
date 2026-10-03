<?php

declare(strict_types=1);

/**
 * ApiTestCase —— 接口测试基类（统一响应体断言）
 *
 * 本文件属于 core（纯 API 后端）的测试基础设施。
 * 用途：把"统一响应体长什么样"这件事变成**一处断言**，避免 17 个模块各写一遍。
 * 谁在调：`tests/Feature` 下所有打接口的测试。
 *
 * 本类**刻意只放 Laravel 没有的东西**：
 *   - 发请求直接用基类自带的 `getJson()` / `postJson()` —— **不要在这里再包一层**，
 *     那只是换个名字，属于"不必要的跳转"。
 *   - 断言"成功"用这里的 `assertApiOk()`，"失败"用 `assertApiError()`。
 *
 * 这两个断言查的是**结构不变量**，不是内部逻辑：
 *   1. 四个字段齐全（code / message / data / meta）
 *   2. 失败时 `data` 必须是 `null`（成功时必须是对象）
 *   3. **HTTP 状态码必须等于 `ErrorCode::httpStatus($code)`** —— 这一条能自动抓出
 *      "改了错误码却忘了改状态码映射"这类漂移，单靠 `assertJsonPath` 抓不到
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/standards.md
 * @see     docs/modules/00-support.md
 */

namespace Tests;

use App\Modules\Support\ErrorCode;
use Illuminate\Testing\TestResponse;

abstract class ApiTestCase extends TestCase
{
    /**
     * 清掉守卫缓存的"当前用户"。
     *
     * 用法：
     *   // 测"登出后令牌失效"这类场景时，在两个请求之间调一次：
     *   $this->postJson('/api/v1/admin/logout', [], $headers);
     *   $this->forgetAuthenticatedUser();                       // ← 必须
     *   $this->assertApiError($this->getJson('/api/v1/admin/me', $headers), ErrorCode::SYS_UNAUTHENTICATED);
     *
     * 边界/注意：
     *   **不调这一下会测出假结果**（已实测踩过）。原因是一个测试方法里的多次
     *   `$this->getJson()` 复用**同一个应用实例**，而 `Illuminate\Auth\RequestGuard::user()`
     *   把解析出的用户缓存在属性里：
     *       if (! is_null($this->user)) { return $this->user; }
     *   下一次请求会**直接返回上一次的登录态，不再重新解析令牌** ——
     *   于是"登出后再访问"依然拿到 200，看起来像令牌没被吊销。
     *
     *   生产环境（PHP-FPM）每个请求都是新容器，不存在这个问题；
     *   但**将来上 Octane 时它就是一个真实的跨请求泄漏**，日志里那条
     *   "请求级数据不要放进单例"的规则说的就是这件事。
     *
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    protected function forgetAuthenticatedUser(): void
    {
        $this->app['auth']->forgetGuards();
    }

    /**
     * 断言响应是"成功的统一响应体"，并可选校验 data 里的字段。
     *
     * 用法：
     *   $res = $this->getJson('/api/v1/health');
     *   $this->assertApiOk($res);
     *   $this->assertApiOk($res, ['service' => 'nmnx-core']);   // 点号路径，等价于 data.service
     *
     * 边界/注意：
     *   1. `$data` 的键用**相对 data 的点号路径**（`'user.name'` 会查 `data.user.name`），
     *      不要自己写成 `'data.user.name'`。
     *   2. 只断言传进来的字段，不要求 data 里没有别的字段 —— 接口加字段不该弄坏测试。
     *
     * @param  TestResponse  $response  被测响应
     * @param  array<string, mixed>  $data  期望的 data 字段（点号路径 => 期望值）
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    protected function assertApiOk(TestResponse $response, array $data = []): void
    {
        $response->assertOk();
        $response->assertJsonStructure(['code', 'message', 'data', 'meta']);
        $response->assertJsonPath('code', ErrorCode::OK);
        $response->assertJsonPath('data', fn ($value) => is_array($value) || is_object($value));

        foreach ($data as $path => $expected) {
            $response->assertJsonPath('data.'.$path, $expected);
        }
    }

    /**
     * 断言响应是"失败的统一响应体"，并校验错误码与 HTTP 状态码的对应关系。
     *
     * 用法：
     *   $res = $this->postJson('/api/v1/admin/login', ['username' => '', 'password' => '']);
     *   $this->assertApiError($res, ErrorCode::SYS_VALIDATION);
     *   $this->assertApiError($res, ErrorCode::SYS_VALIDATION, ['errors' => ['username' => []]]);
     *
     * 边界/注意：
     *   HTTP 状态码**不是**从参数传的，而是用 `ErrorCode::httpStatus()` 反查 ——
     *   这样"接口实际返回的状态码"和"错误码表里登记的状态码"必须一致，不一致就红。
     *
     * @param  TestResponse  $response  被测响应
     * @param  string  $code  期望的错误码（`ErrorCode` 常量）
     * @param  array<string, mixed>  $meta  期望的 meta 字段（点号路径 => 期望值）
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    protected function assertApiError(TestResponse $response, string $code, array $meta = []): void
    {
        $response->assertStatus(ErrorCode::httpStatus($code));
        $response->assertJsonStructure(['code', 'message', 'data', 'meta']);
        $response->assertJsonPath('code', $code);
        $response->assertJsonPath('data', null);

        foreach ($meta as $path => $expected) {
            $response->assertJsonPath('meta.'.$path, $expected);
        }
    }
}
