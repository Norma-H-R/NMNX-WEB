<?php

declare(strict_types=1);

/**
 * 鉴权提示（简体中文）
 *
 * 本文件属于 core（纯 API 后端）的 Support 层。
 * 用途：给 Laravel 内置的鉴权失败提示中文化。
 *       ⚠️ 我们自己的登录走的是 `AdminAuthService` + 自定义错误码
 *       （`AUTH_INVALID_CREDENTIALS`），**不经过这里的 `failed`**。
 *       这个文件是给"将来用到 Laravel 内置 attempt()/重置密码"时兜底用的。
 * 谁在调：Laravel 的 Auth 组件。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/01-auth.md
 */

return [

    'failed' => '账号或密码不正确。',
    'password' => '密码不正确。',
    'throttle' => '登录尝试过于频繁，请在 :seconds 秒后重试。',

];
