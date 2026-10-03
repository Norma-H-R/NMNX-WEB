<?php

declare(strict_types=1);

/**
 * ForumBoard —— 论坛版块（模块 11）
 *
 * 本文件属于 core（纯 API 后端）的 Forum 模块。
 * 用途：版块（策略讨论 / 使用求助 / 经验分享 / 公告与更新 / 灌水区），
 *       列表页横排筛选就是按 `slug` 走 `?board=slug`。
 * 谁在调：ForumService、`/public/forum/boards` 接口。
 *
 * ⚠️ `post_count` / `reply_count` / `last_reply_at` 是**冗余计数**：
 *    版块列表要显示"每版多少帖、最后回复时间"，每次 COUNT(*)+MAX() 会拖垮首页。
 *    一致性靠"发帖/回复时在同一事务里维护"，**不要**在别处直接改这三个字段。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/11-forum.md
 */

namespace App\Modules\Forum\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ForumBoard extends Model
{
    /**
     * 可批量赋值字段。
     *
     * ⚠️ **不含 `*_count` 与 `last_reply_at`**：它们由发帖/回复逻辑维护，
     * 放开批量赋值等于给"凭空改计数"开口子。
     *
     * @var list<string>
     */
    protected $fillable = ['slug', 'name', 'description', 'hue', 'sort'];

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
            'hue' => 'integer',
            'sort' => 'integer',
            'last_reply_at' => 'datetime',
        ];
    }

    /**
     * 本版块下的帖子。
     *
     * @return HasMany<ForumPost, $this>  帖子集合
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function posts(): HasMany
    {
        return $this->hasMany(ForumPost::class, 'board_id');
    }
}
