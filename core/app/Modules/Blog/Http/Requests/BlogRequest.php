<?php

declare(strict_types=1);

/**
 * BlogRequest —— 博客新建 / 编辑的入参校验（模块 10）
 *
 * 本文件属于 core（纯 API 后端）的 Blog 模块，按 `docs/modules/10-blog.md` 实现。
 * 用途：校验会员提交的博客内容，并提供 `toBlogData()` 把"请求里真正出现的字段"
 *       取成一个数组交给 BlogService。
 * 谁在调：MemberBlogController::store() / update()。
 *
 * 为什么"只取出现的字段"很重要：
 *   编辑是**部分更新**（PUT 但语义接近 PATCH）：前端只发改过的字段。
 *   如果这里给每个字段都填上默认值，那么"只改标题"的请求会顺便把正文清空 ——
 *   这类 bug 在界面上表现为"我改个标题，正文没了"，而且极难复现。
 *   所以 `toBlogData()` 一律用 `$this->has()` 判断，没传的键**根本不出现**。
 *
 * 为什么 `status` 只允许 draft / published：
 *   `hidden`（官方下架）是**后台**的动作，会员自己不能设 —— 否则他可以把内容
 *   标记成"已被官方下架"，反过来冒充管理动作。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/10-blog.md
 */

namespace App\Modules\Blog\Http\Requests;

use App\Modules\Blog\Models\Blog;
use App\Modules\Blog\Models\BlogAttachment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BlogRequest extends FormRequest
{
    /** 单篇博客最多允许的附件数 */
    private const MAX_ATTACHMENTS = 20;

    /** 一篇博客最多允许的标签数 */
    private const MAX_TAGS = 10;

    /**
     * 是否允许本次请求。
     *
     * 用法：
     *   // 由框架在进控制器前调用
     *
     * 边界/注意：
     *   **恒为 true**：会员身份的鉴权已经由路由上的 `auth:member` 中间件完成，
     *   这里再判一次等于把同一件事写两遍。真正的"这篇是不是我的"由
     *   BlogService 判（返回 404 而不是 403，见那边的注释）。
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
     *   POST /api/v1/member/blogs  { "title":"…", "body_md":"…" }
     *
     * 边界/注意：
     *   1. 全部字段都是 `sometimes` —— 新建时用 `required` 的那几个由
     *      `toBlogData()` 的存在性决定（新建走的是同一套规则，
     *      真正的"必须给标题"由数据库的非空约束 + 服务层的赋值兜住）。
     *      这样新建和编辑能共用一份规则，不必维护两套。
     *   2. **`slug` 不在规则里**：前端传了也会被忽略（它由后端从标题生成），
     *      写在规则里等于暗示"这个字段可以传"。
     *   3. `attachments.*.kind` 只允许枚举值，且**服务端不信任它** ——
     *      真正的 kind 是上传时按 MIME 判定的（见 BlogAttachment::kindFromMime）。
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
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'body_md' => ['sometimes', 'required', 'string', 'max:200000'],
            'excerpt' => ['sometimes', 'nullable', 'string', 'max:500'],
            'cover_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_TAGS],
            'tags.*' => ['string', 'max:32'],
            'status' => ['sometimes', 'required', Rule::in([Blog::STATUS_DRAFT, Blog::STATUS_PUBLISHED])],
            'allow_reference' => ['sometimes', 'boolean'],

            'attachments' => ['sometimes', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*.kind' => ['required', Rule::in([
                BlogAttachment::KIND_IMAGE,
                BlogAttachment::KIND_AUDIO,
                BlogAttachment::KIND_VIDEO,
                BlogAttachment::KIND_DOC,
                BlogAttachment::KIND_SHEET,
                BlogAttachment::KIND_PDF,
                BlogAttachment::KIND_OTHER,
            ])],
            'attachments.*.url' => ['required', 'string', 'max:500'],
            'attachments.*.name' => ['required', 'string', 'max:255'],
            'attachments.*.size' => ['required', 'integer', 'min:0'],
            'attachments.*.mime' => ['sometimes', 'string', 'max:128'],
        ];
    }

    /**
     * 取本次请求真正提交的博客字段。
     *
     * 用法：
     *   $blog = $blogService->update($user, $blog, $request->toBlogData());
     *
     * 边界/注意：
     *   只放**请求里出现过的键**（`$this->has()`）。这正是"部分更新"的实现方式：
     *   没传的字段在 Service 那边不会被赋值，也就不会被清空。
     *
     * @return array<string, mixed>  可交给 BlogService 的数据
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function toBlogData(): array
    {
        $data = [];

        foreach (['title', 'body_md', 'excerpt', 'cover_url', 'status'] as $field) {
            if ($this->has($field)) {
                $data[$field] = $this->input($field);
            }
        }

        if ($this->has('tags')) {
            $data['tags'] = array_values((array) $this->input('tags', []));
        }

        if ($this->has('allow_reference')) {
            $data['allow_reference'] = $this->boolean('allow_reference');
        }

        if ($this->has('attachments')) {
            $data['attachments'] = array_values((array) $this->input('attachments', []));
        }

        return $data;
    }
}
