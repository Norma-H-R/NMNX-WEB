<?php

declare(strict_types=1);

/**
 * RoleTone —— 角色徽章的配色
 *
 * 本文件属于 core（纯 API 后端）的 Rbac 模块。
 * 用途：把"这个角色用什么颜色"这件**展示层**的事也落库 —— 角色是数据库里的数据，
 *       后台渲染徽章时要拿到配色，不能在代码里按角色名硬编码（自定义角色就没有名字可对）。
 * 谁在调：角色管理接口、后台的角色徽章。
 *
 * ⚠️ 这里**只存色号（代码）**，具体色值（`#f2d18d` 这类）留在前端 —— 那是主题，
 *    换配色不该动数据库。前端 `admin/src/types/user.ts` 的 `TONE_COLORS` 是色值表。
 *
 * 取值与前端 `ToneName` 联合类型**逐字对齐**，不要各加各的。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/08-rbac.md
 */

namespace App\Modules\Rbac\Enums;

enum RoleTone: string
{
    case Cyan = 'cyan';

    case Violet = 'violet';

    case Green = 'green';

    case Gold = 'gold';

    case Red = 'red';

    case Slate = 'slate';

    /**
     * 取给界面看的中文名（新建角色时的下拉选项用）。
     *
     * 用法：
     *   RoleTone::Gold->label();   // '金'
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
            self::Cyan => '青',
            self::Violet => '紫',
            self::Green => '绿',
            self::Gold => '金',
            self::Red => '红',
            self::Slate => '灰',
        };
    }

    /**
     * 全部取值 + 中文名，给接口做下拉选项用。
     *
     * 用法：
     *   RoleTone::options();   // [['value' => 'cyan', 'label' => '青'], …]
     *
     * @return list<array{value: string, label: string}> 选项列表
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $tone): array => ['value' => $tone->value, 'label' => $tone->label()],
            self::cases(),
        );
    }
}
