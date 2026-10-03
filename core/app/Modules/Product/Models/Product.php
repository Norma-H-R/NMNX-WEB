<?php

declare(strict_types=1);

/**
 * Product —— 产品介绍（模块 14）
 *
 * 本文件属于 core（纯 API 后端）的 Product 模块。
 * 用途：官网 `/products` 列表与 `/products/{slug}` 详情展示的产品（主控 EA、跟随端、回测引擎…）。
 * 谁在调：`/public/products` 接口、后台产品管理。
 *
 * 与博客/论坛的三处不同（**别照搬**）：
 *   1. **没有作者** —— 它是官方维护的展示内容，不是 UGC；
 *   2. **没有软删语义** —— 下架就直接删或改 `status`，不需要"回收站"；
 *   3. `status` 是**产品阶段**（`stable` / `beta` / `planned`），
 *      与内容的"发布状态"完全不是一回事（中文映射在前端）。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/14-product.md
 */

namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;

final class Product extends Model
{
    /** 产品阶段：稳定版 */
    public const STATUS_STABLE = 'stable';

    /** 产品阶段：测试版 */
    public const STATUS_BETA = 'beta';

    /** 产品阶段：规划中 */
    public const STATUS_PLANNED = 'planned';

    /**
     * 可批量赋值字段。
     *
     * @var list<string>
     */
    protected $fillable = ['slug', 'name', 'version', 'tagline', 'status', 'tags', 'body_md', 'body_html', 'hue', 'sort'];

    /**
     * 取字段的类型转换表。
     *
     * @return array<string, string>  字段 => cast 规则
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'hue' => 'integer',
            'sort' => 'integer',
        ];
    }
}
