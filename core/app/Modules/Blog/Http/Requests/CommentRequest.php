<?php

declare(strict_types=1);

/**
 * CommentRequest —— 发表评论的入参校验（模块 10）
 *
 * 本文件属于 core（纯 API 后端）的 Blog 模块，按 `docs/modules/10-blog.md` 实现。
 * 用途：校验评论的载体（`target_type` / `target_id`）、父评论与正文。
 * 谁在调：CommentController::store()。
 *
 * ⚠️ `target_type` 本期**只允许 `blog`**。
 *    论坛落地时这里加一个 `forum_post` 即可 —— 但请连同 CommentService 的
 *    `bumpTargetCommentCount()` 一起加，漏掉那边的话评论数不会更新。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/10-blog.md
 */

namespace App\Modules\Blog\Http\Requests;

use App\Modules\Blog\Services\ReactionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CommentRequest extends FormRequest
{
    /** 单条评论的最大字数 */
    private const MAX_BODY = 2000;

    /**
     * 是否允许本次请求。
     *
     * 用法：
     *   // 由框架在进控制器前调用
     *
     * 边界/注意：
     *   恒为 true —— 鉴权在路由中间件（`auth:member`）。
     *   "父评论是否属于同一个目标"这类**业务**校验放在 CommentService，
     *   因为它要用到数据库里的实际数据，而这里只该管"形状对不对"。
     *
     * @return bool 恒为 true
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
     *   POST /api/v1/comments
     *   { "target_type":"blog", "target_id":12, "parent_id":451, "body":"…" }
     *
     * 边界/注意：
     *   1. `parent_id` 是**可选**的：不传就是顶层评论。
     *   2. 正文是纯文本，**不支持 Markdown**（模块文档决策 9 明说了）——
     *      评论再开一套渲染面，等于把一个 XSS 风险点复制一遍。
     *   3. `target_id` 只校验"是正整数"，**不在这里查它存不存在**：
     *      那样会多一次查询，而 CommentService 拼树时本来就要读目标。
     *
     * @return array<string, mixed>  校验规则
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    论坛落地时把 `forum_post` 加进 target_type 的白名单
     */
    public function rules(): array
    {
        return [
            'target_type' => ['required', 'string', Rule::in([ReactionService::TARGET_BLOG])],
            'target_id' => ['required', 'integer', 'min:1'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'body' => ['required', 'string', 'max:'.self::MAX_BODY],
        ];
    }

    /**
     * 取可交给 CommentService 的数据。
     *
     * 用法：
     *   $comment = $commentService->create($user, $request->toCommentData());
     *
     * 边界/注意：
     *   `parent_id` **只在请求里出现时才带键**。带上 `null` 和不带键
     *   在服务层是两种含义（前者被拒绝、后者是顶层），别把它们统一掉。
     *
     * @return array<string, mixed>  可交给 CommentService 的数据
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function toCommentData(): array
    {
        $data = [
            'target_type' => (string) $this->string('target_type'),
            'target_id' => (int) $this->input('target_id'),
            'body' => (string) $this->string('body'),
        ];

        if ($this->filled('parent_id')) {
            $data['parent_id'] = (int) $this->input('parent_id');
        }

        return $data;
    }
}
