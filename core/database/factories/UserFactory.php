<?php

declare(strict_types=1);

/**
 * UserFactory —— 会员（用户总表）的测试造数工厂
 *
 * 本文件属于 core（纯 API 后端）的 Member 模块测试基础设施。
 * 用途：测试里造一个会员只要 `User::factory()->create()`。
 * 谁在调：`tests/Feature/Member/*` 下的测试，以及 `DatabaseSeeder`。
 *
 * ⚠️ 这里的 `'password' => 'password'` 是**测试夹具**的密码，
 * 和 `AdminSeeder` 里的开发密码 `11111` 是两回事，不要混着记。
 *
 * @version 0.2.0
 *
 * @since   2026-10-03
 * @see     docs/modules/07-member.md
 */

namespace Database\Factories;

use App\Models\User;
use App\Modules\Member\Enums\MemberStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * 当前工厂用的密码缓存（避免每个实例都重新哈希一次）。
     */
    protected static ?string $password;

    /**
     * 生成一条默认的会员数据。
     *
     * 用法：
     *   $user = User::factory()->create();
     *   $user = User::factory()->disabled()->create();
     *   $user = User::factory()->create(['email' => 'a@b.com']);
     *
     * 边界/注意：
     *   1. `password` 这里给明文 `'password'` 并**手动 Hash::make**（不是靠 hashed cast）——
     *      沿用 Laravel 默认工厂写法，测试里要校验密码就用 `'password'`。
     *   2. `phone` 默认 null（一期注册不填手机号），符合 `nullable()->unique()`。
     *
     * @return array<string, mixed> 字段默认值
     *
     * @version 0.2.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => MemberStatus::Active,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * 造一个"邮箱未验证"的会员。
     *
     * 用法：
     *   User::factory()->unverified()->create();
     *
     * @return static 工厂自身，可继续链式调用
     *
     * @version 0.2.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * 造一个"已禁言"的会员：**能登录**，但不能发帖/评论。
     *
     * 用法：
     *   User::factory()->muted()->create();
     *
     * 边界/注意：
     *   覆盖"禁言 ≠ 封号"这条语义边界 —— 禁言的人**必须**还能登录，
     *   否则他连自己的授权码都看不到。
     *
     * @return static 工厂自身，可继续链式调用
     *
     * @version 0.3.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function muted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MemberStatus::Muted,
        ]);
    }

    /**
     * 造一个"已封禁"的会员：不能登录。
     *
     * 用法：
     *   User::factory()->banned()->create();
     *
     * 边界/注意：
     *   用于覆盖"账号被封禁"分支 —— 密码正确也必须登录失败。
     *
     * @return static 工厂自身，可继续链式调用
     *
     * @version 0.3.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function banned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MemberStatus::Banned,
        ]);
    }
}
