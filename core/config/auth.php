<?php

use App\Models\User;
use App\Modules\Auth\Models\Admin;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        /*
         * 后台管理者守卫（/api/v1/admin/* 用 `auth:admin`）。
         * 驱动是 sanctum（Bearer 令牌），**不是** Cookie 会话。
         *
         * ⚠️ provider 这一行是**安全防线的主体**，不是装饰：
         *    Sanctum 的 Guard 在 isValidAccessToken() 里调 hasValidProvider()，
         *    判定 `$tokenable instanceof config('auth.providers.<provider>.model')`
         *    （vendor/laravel/sanctum/src/Guard.php:130,145-153）。
         *    而 **provider 为 null 时它直接返回 true，任何令牌都放行** ——
         *    Sanctum 自动注册的那个 `sanctum` 守卫正是 provider = null。
         *
         *    也就是说：`auth:sanctum` 下会员令牌可以读 admin 接口；
         *    换成 `auth:admin`（provider = admins）之后才真正隔离。
         *    回归测试在 tests/Feature/Auth/GuardIsolationTest.php —— 别删。
         */
        'admin' => [
            'driver' => 'sanctum',
            'provider' => 'admins',
        ],

        /*
         * 前台会员守卫（/api/v1/member/* 用 `auth:member`）。
         * 会员就是 users 总表（App\Models\User），所以 provider 复用 `users`。
         *
         * 与管理者的隔离靠 Sanctum 的 hasValidProvider()：
         *   admin 令牌的 tokenable 是 Admin、member 令牌的是 User，两者 instanceof 互不匹配
         *   （vendor/laravel/sanctum/src/Guard.php:145-153）。回归测试别删。
         */
        'member' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', User::class),
        ],

        /*
         * 后台管理者。与上面的 `users`（前台会员）是**两张表、两个模型**（需求 D1）。
         * 注意这里的 `env('AUTH_MODEL', ...)` 只作用于会员，管理员**不允许**用环境变量覆盖 ——
         * 管理员表是整个后台的入口凭据，配置漂移的代价太高。
         */
        'admins' => [
            'driver' => 'eloquent',
            'model' => Admin::class,
        ],

        // 'users' => [
        //     'driver' => 'database',
        //     'table' => 'users',
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
