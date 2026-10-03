<?php

declare(strict_types=1);

/**
 * BlogAttachment —— 博客附件（模块 10）
 *
 * 本文件属于 core（纯 API 后端）的 Blog 模块。
 * 用途：博客里的图片 / 音频 / 视频 / 文档 / 表格 / PDF / 其它。
 *       前端按 `kind` 分流展示：图片铺图、音视频内嵌播放器、其余给"图标 + 文件名 + 大小 + 下载"。
 * 谁在调：上传接口（写）、BlogResource（出口）。
 *
 * ⚠️ `kind` **由后端按 MIME 判定**，不接受前端传 —— 前端说"这是图片"而实际是 HTML，
 *    铺出去就是 XSS。所以存 `mime` 原文，`kind` 是**推出来的结果**（以后规则改了还能重算）。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/10-blog.md
 */

namespace App\Modules\Blog\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BlogAttachment extends Model
{
    /** 展示类型：图片 */
    public const KIND_IMAGE = 'image';

    /** 展示类型：音频 */
    public const KIND_AUDIO = 'audio';

    /** 展示类型：视频 */
    public const KIND_VIDEO = 'video';

    /** 展示类型：文档 */
    public const KIND_DOC = 'doc';

    /** 展示类型：表格 */
    public const KIND_SHEET = 'sheet';

    /** 展示类型：PDF */
    public const KIND_PDF = 'pdf';

    /** 展示类型：其它（一律给"下载"） */
    public const KIND_OTHER = 'other';

    /**
     * 可批量赋值字段。
     *
     * ⚠️ `blog_id` 与 `user_id` 必须在这里 —— 它们是**关联键**，
     *    而 `BlogService::syncAttachments()` 是用 `create()` 写入的。
     *    漏掉的话 Eloquent 会**静默丢弃**这两列（不报错），
     *    结果是附件永远挂不到博客上，而且查不出原因。
     *
     * @var list<string>
     */
    protected $fillable = ['blog_id', 'user_id', 'kind', 'url', 'name', 'size', 'mime', 'sort'];

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
            'size' => 'integer',
            'sort' => 'integer',
        ];
    }

    /**
     * 按 MIME 判定展示类型。
     *
     * 用法：
     *   BlogAttachment::kindFromMime('image/webp');              // 'image'
     *   BlogAttachment::kindFromMime('application/pdf');          // 'pdf'
     *   BlogAttachment::kindFromMime('audio/mpeg');               // 'audio'
     *   BlogAttachment::kindFromMime('application/vnd…sheet');    // 'sheet'
     *   BlogAttachment::kindFromMime('application/x-msdownload'); // 'other'
     *
     * 边界/注意：
     *   判不出来**一律归 `other`**（给下载），绝不"猜成图片" ——
     *   猜错等于把一个 HTML 文件当图片铺进页面。
     *
     * @param  string  $mime  MIME 原文
     * @return string  展示类型常量
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public static function kindFromMime(string $mime): string
    {
        $mime = strtolower(trim($mime));

        return match (true) {
            str_starts_with($mime, 'image/') => self::KIND_IMAGE,
            str_starts_with($mime, 'audio/') => self::KIND_AUDIO,
            str_starts_with($mime, 'video/') => self::KIND_VIDEO,
            $mime === 'application/pdf' => self::KIND_PDF,
            str_contains($mime, 'spreadsheet') || str_contains($mime, 'excel') => self::KIND_SHEET,
            str_contains($mime, 'word') || str_contains($mime, 'text/plain') => self::KIND_DOC,
            default => self::KIND_OTHER,
        };
    }

    /**
     * 所属博客。
     *
     * @return BelongsTo<Blog, $this>  博客
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function blog(): BelongsTo
    {
        return $this->belongsTo(Blog::class);
    }

    /**
     * 上传者。
     *
     * 用法：
     *   $attachment->user?->name;   // 可能为 null：补列之前上传的记录没有归属
     *
     * 边界/注意：
     *   这一列是**事后补的**（见 2026_10_04_120000 那次迁移），
     *   所以老数据可能为 null，用的时候别直接 `->user->name` 解引用。
     *
     * @return BelongsTo<User, $this>  上传者
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
