<?php

declare(strict_types=1);

/**
 * AdminStatus —— 后台管理者账号状态
 *
 * 本文件属于 core（纯 API 后端）的 Auth 模块。
 * 用途：给 `admins.status` 一个**类型安全**的取值集合，避免在代码里散落 `'active'`
 *       这种字符串字面量（拼错时不会有任何提示）。
 *       禁用状态为什么必须有：对应 `ErrorCode::AUTH_ACCOUNT_DISABLED` ——
 *       停用一个管理员应该是"禁用"，不是"删号"，否则审计日志里的历史记录会指向不存在的账号。
 * 谁在调：`Admin` 模型的 casts、登录服务、后台的账号管理。
 *
 * 落库形态：**存字符串值**（`active` / `disabled`），不存枚举名、也不存数字 ——
 * 存字符串的可读性最好，且以后加值不需要改列类型。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/01-auth.md
 */

namespace App\Modules\Auth\Enums;

enum AdminStatus: string
{
    /** 正常可用，允许登录 */
    case Active = 'active';

    /** 已禁用，禁止登录（不是删号，历史审计仍可见） */
    case Disabled = 'disabled';

    /**
     * 取给界面看的中文文案。
     *
     * 用法：
     *   AdminStatus::Disabled->label();   // '已禁用'
     *   $admin->status->label();          // 模型 cast 之后直接调
     *
     * 边界/注意：
     *   这是**界面文案**，不是 API 里 `code` 那种机器标识。
     *   接口出参请用 `->value`（`'disabled'`），不要用 label —— label 会变，value 不会。
     *
     * @return string 中文文案
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => '正常',
            self::Disabled => '已禁用',
        };
    }

    /**
     * 判断是否允许登录。
     *
     * 用法：
     *   if (! $admin->status->canLogin()) { throw new ApiException(ErrorCode::AUTH_ACCOUNT_DISABLED); }
     *
     * 边界/注意：
     *   把"能不能登录"的判断收在这里，而不是在登录服务里写 `$status === 'active'` ——
     *   以后新增状态（比如 `locked`）时，只需要改这一个地方。
     *
     * @return bool 允许登录返回 true
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function canLogin(): bool
    {
        return $this === self::Active;
    }
}
