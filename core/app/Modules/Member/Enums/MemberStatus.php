<?php

declare(strict_types=1);

/**
 * MemberStatus —— 会员账号状态
 *
 * 本文件属于 core（纯 API 后端）的 Member 模块。
 * 用途：给 `users.status` 一个类型安全的取值集合，避免散落 `'active'` 字符串字面量。
 * 谁在调：`App\Models\User` 的 casts、会员登录服务、后台的用户管理。
 *
 * ⚠️ 取值**必须与 admin 前端的契约逐字对齐**（`admin/src/types/user.ts` 的 `UserStatus`）：
 *   `'active' | 'muted' | 'banned'`
 *   之前这里是 `active / disabled` 两值，属于和契约不一致，已按契约改成三值。
 *
 * 三个状态的语义边界（决定了登录与发言能不能做）：
 *   active  正常 —— 能登录、能发言
 *   muted   **禁言** —— **能登录**，但不能发帖/评论（论坛最常见的处罚，不该等于封号）
 *   banned  **封禁** —— 不能登录
 *
 * 与 `AdminStatus` 是**两个独立枚举**（各管各的状态集合）：后台管理员的账号语义
 * 没有"禁言"这一档，不要为了省一个枚举把它们合并。
 *
 * @version 0.2.0
 *
 * @since   2026-10-03
 * @see     docs/modules/07-member.md
 */

namespace App\Modules\Member\Enums;

enum MemberStatus: string
{
    /** 正常可用：能登录、能发言 */
    case Active = 'active';

    /** 已禁言：**能登录**，但不能发帖/评论 */
    case Muted = 'muted';

    /** 已封禁：不能登录（不是删号） */
    case Banned = 'banned';

    /**
     * 取给界面看的中文文案。
     *
     * 用法：
     *   MemberStatus::Muted->label();   // '已禁言'
     *   $user->status->label();         // 模型 cast 之后直接调
     *
     * 边界/注意：
     *   这是**界面文案**，不是协议。接口出参请用 `->value`（`'muted'`）。
     *
     * @return string 中文文案
     *
     * @version 0.2.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => '正常',
            self::Muted => '已禁言',
            self::Banned => '已封禁',
        };
    }

    /**
     * 判断是否允许登录。
     *
     * 用法：
     *   if (! $user->status->canLogin()) { throw new ApiException(ErrorCode::AUTH_ACCOUNT_DISABLED); }
     *
     * 边界/注意：
     *   **禁言是允许登录的** —— 只不准发言。把禁言也挡在登录外，
     *   用户连自己的授权码都看不到了，处罚就过重了。
     *
     * @return bool 允许登录返回 true
     *
     * @version 0.2.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function canLogin(): bool
    {
        return $this !== self::Banned;
    }

    /**
     * 判断是否允许发言（发帖 / 评论 / 投稿）。
     *
     * 用法：
     *   if (! $user->status->canPost()) { throw new ApiException(ErrorCode::MEMBER_MUTED); }
     *
     * 边界/注意：
     *   论坛、博客、评论这些"写内容"的地方**都要先过这一关**，
     *   不能只在登录时判一次 —— 用户可能登录后才被禁言。
     *
     * @return bool 允许发言返回 true
     *
     * @version 0.2.0
     *
     * @since   2026-10-03
     *
     * @todo    等论坛/博客模块落地时，把这道检查收进一个统一的中间件
     */
    public function canPost(): bool
    {
        return $this === self::Active;
    }
}
