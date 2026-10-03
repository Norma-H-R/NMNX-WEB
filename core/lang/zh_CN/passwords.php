<?php

declare(strict_types=1);

/**
 * 密码重置提示（简体中文）
 *
 * 本文件属于 core（纯 API 后端）的 Support 层。
 * 用途：密码重置流程的提示中文化。
 *       ⚠️ 当前**还没有**重置密码接口（见 01-auth.md 待办），
 *       这个文件先备着，免得将来加接口时又冒出一句英文。
 * 谁在调：Laravel 的 Password Broker。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/01-auth.md
 */

return [

    'reset' => '密码已重置。',
    'sent' => '密码重置链接已发送到你的邮箱。',
    'throttled' => '请稍后再试。',
    'token' => '密码重置令牌无效。',
    'user' => '找不到使用该邮箱的用户。',

];
