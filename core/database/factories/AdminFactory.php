<?php

declare(strict_types=1);

/**
 * AdminFactory —— 后台管理者的测试造数工厂
 *
 * 本文件属于 core（纯 API 后端）的 Auth 模块测试基础设施。
 * 用途：让测试里造一个管理员只要一行 `Admin::factory()->create()`，
 *       不必每个测试手写 `Admin::create([...])` 的一堆字段。
 * 谁在调：`tests/Feature/Auth/*` 下的测试。
 *
 * 放在 `database/factories/` 是因为 `composer.json` 的 PSR-4 里
 * `Database\Factories\` 指向这里（模型在模块命名空间，工厂仍在框架约定的位置）。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/01-auth.md
 */

namespace Database\Factories;

use App\Modules\Auth\Enums\AdminStatus;
use App\Modules\Auth\Models\Admin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Admin>
 */
class AdminFactory extends Factory
{
    /**
     * 工厂对应的模型。
     *
     * 用法：
     *   Admin::factory()->create();
     *
     * 边界/注意：
     *   **必须显式指定**。Laravel 按工厂类名反推模型，`AdminFactory` 会推到
     *   `App\Models\Admin` —— 而我们的模型在模块命名空间里，不指定就会报类不存在。
     *
     * @var class-string<Admin>
     */
    protected $model = Admin::class;

    /**
     * 生成一条默认的管理员数据。
     *
     * 用法：
     *   $admin = Admin::factory()->create();                        // 随机账号、密码 password
     *   $admin = Admin::factory()->create(['username' => 'root']);   // 指定账号
     *   $admin = Admin::factory()->disabled()->create();             // 造一个被禁用的
     *
     * 边界/注意：
     *   1. `password` 这里直接给**明文** `'password'`，由模型的 `hashed` cast 负责哈希。
     *      测试里要校验密码就用 `'password'`，不要在这里 `Hash::make()`（会二次哈希）。
     *   2. `username` 用随机后缀保证唯一约束不被撞破。
     *
     * @return array<string, mixed> 字段默认值
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function definition(): array
    {
        return [
            'username' => 'admin_'.Str::lower(Str::random(6)),
            'name' => '测试管理员',
            'email' => Str::lower(Str::random(8)).'@nmnx.test',
            'password' => 'password',
            'status' => AdminStatus::Active,
            'last_login_at' => null,
            'last_login_ip' => null,
        ];
    }

    /**
     * 造一个"已禁用"的管理员。
     *
     * 用法：
     *   Admin::factory()->disabled()->create();
     *
     * 边界/注意：
     *   用于覆盖 `ErrorCode::AUTH_ACCOUNT_DISABLED` 那条分支 ——
     *   被禁用的账号即使密码正确也必须登录失败。
     *
     * @return static 工厂自身，可继续链式调用 create()
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function disabled(): static
    {
        return $this->state(fn (): array => ['status' => AdminStatus::Disabled]);
    }
}
