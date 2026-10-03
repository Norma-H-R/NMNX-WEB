<?php

declare(strict_types=1);

/**
 * ReactionRequest —— 互动类型的入参校验（模块 10）
 *
 * 本文件属于 core（纯 API 后端）的 Blog 模块，按 `docs/modules/10-blog.md` 实现。
 * 用途：校验点赞 / 收藏 / 拉黑 / 踩请求里的 `type`。
 * 谁在调：MemberBlogController::react()、CommentController::react()。
 *
 * 这里只做**粗校验**（type 是那几个字符串之一），真正"哪种目标允许哪些类型"
 * 的判定在 ReactionService —— 因为那取决于目标类型（博客有 `block`、
 * 评论有 `dislike`），而 Request 拿不到目标模型。
 *
 * ⚠️ 白名单是**两边取值的并集**。看起来"不够严格"，但这是刻意的：
 *    如果这里按博客的类型收窄，评论的 `dislike` 就会在进入 Service 之前被拒，
 *    而报出来的会是"参数校验失败"（422）而不是更准确的
 *    "该目标不支持这个互动类型"。分开两层的意义就在于错误信息更贴近真相。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/10-blog.md
 */

namespace App\Modules\Blog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReactionRequest extends FormRequest
{
    /**
     * 是否允许本次请求。
     *
     * @return bool 恒为 true（鉴权在路由中间件）
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 校验规则。
     *
     * 用法：
     *   POST /api/v1/member/blogs/12/reactions   { "type":"like" }
     *   POST /api/v1/comments/451/reactions      { "type":"dislike" }
     *
     * 边界/注意：
     *   并集 = `like` / `favorite` / `block`（博客）/ `dislike`（评论）。
     *   只给一个 `string` 而不给 `max` 是故意的：取值由 `in` 限定，
     *   长度约束在这里没有意义。
     *
     * @return array<string, mixed>  校验规则
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['like', 'favorite', 'block', 'dislike'])],
        ];
    }
}
