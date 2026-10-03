<?php

declare(strict_types=1);

/**
 * ErrorCode —— 全站 API 错误码
 *
 * 本文件属于 core（纯 API 后端）的 Support 模块。
 * 用途：给前端（face / admin）与 EA 客户端一套**稳定的、机器可读的**错误标识。
 *       客户端按 code 分支，不要按 message 分支（message 将来要国际化）。
 * 谁在调：ApiResponse::fail()、ApiException、各模块的业务异常。
 *
 * 扩展规则：**新增错误码只在这里加常量 + 两条映射，不要在各模块里拼字符串。**
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/00-support.md
 */

namespace App\Modules\Support;

final class ErrorCode
{
    /** 成功。HTTP 200 */
    public const OK = 'OK';

    // ------------------------------------------------------------------
    // 系统级
    // ------------------------------------------------------------------

    /** 服务端内部错误。HTTP 500 */
    public const SYS_INTERNAL = 'SYS_INTERNAL';

    /** 入参校验失败（表单校验）。HTTP 422 */
    public const SYS_VALIDATION = 'SYS_VALIDATION';

    /** 资源不存在。HTTP 404 */
    public const SYS_NOT_FOUND = 'SYS_NOT_FOUND';

    /** 请求方法不允许。HTTP 405 */
    public const SYS_METHOD_NOT_ALLOWED = 'SYS_METHOD_NOT_ALLOWED';

    /** 未登录 / 令牌无效或过期。HTTP 401 */
    public const SYS_UNAUTHENTICATED = 'SYS_UNAUTHENTICATED';

    /** 已登录但无权限。HTTP 403 */
    public const SYS_FORBIDDEN = 'SYS_FORBIDDEN';

    /** 触发限流。HTTP 429 */
    public const SYS_RATE_LIMITED = 'SYS_RATE_LIMITED';

    // ------------------------------------------------------------------
    // 鉴权（模块 01 Auth / 模块 07 Member）
    // ------------------------------------------------------------------

    /** 账号或密码不正确。HTTP 401 */
    public const AUTH_INVALID_CREDENTIALS = 'AUTH_INVALID_CREDENTIALS';

    /** 账号被禁用。HTTP 403 */
    public const AUTH_ACCOUNT_DISABLED = 'AUTH_ACCOUNT_DISABLED';

    // ------------------------------------------------------------------
    // 授权（模块 03 License）
    // ------------------------------------------------------------------

    /** 授权码不存在或已作废。HTTP 403 */
    public const LICENSE_INVALID = 'LICENSE_INVALID';

    /** 授权已过期（且超出宽限期）。HTTP 403 */
    public const LICENSE_EXPIRED = 'LICENSE_EXPIRED';

    /** 产品标识无法解析到对应的 Driver。HTTP 404 */
    public const LICENSE_PRODUCT_UNKNOWN = 'LICENSE_PRODUCT_UNKNOWN';

    /** 机器 / 账号与授权记录不匹配。HTTP 403 */
    public const LICENSE_MACHINE_MISMATCH = 'LICENSE_MACHINE_MISMATCH';

    // ------------------------------------------------------------------
    // EA 接入（模块 04 Client）
    // ------------------------------------------------------------------

    /** 签名校验不通过。HTTP 401 */
    public const CLIENT_SIGN_INVALID = 'CLIENT_SIGN_INVALID';

    /** timestamp 超出允许的时间窗。HTTP 401 */
    public const CLIENT_TIMESTAMP_OUT_OF_WINDOW = 'CLIENT_TIMESTAMP_OUT_OF_WINDOW';

    /** nonce 已被使用过（重放）。HTTP 401 */
    public const CLIENT_NONCE_REPLAYED = 'CLIENT_NONCE_REPLAYED';

    // ------------------------------------------------------------------
    // 内容（模块 02 Content）
    // ------------------------------------------------------------------

    /** 内容不存在，或存在但当前访问者无权查看（私有内容对外一律按"不存在"处理）。HTTP 404 */
    public const CONTENT_NOT_FOUND = 'CONTENT_NOT_FOUND';

    // ------------------------------------------------------------------
    // 博客与评论（模块 10 Blog）
    // ------------------------------------------------------------------

    /** 博客不存在，或存在但对外不可见（草稿 / 已下架对外一律按"不存在"处理）。HTTP 404 */
    public const BLOG_NOT_FOUND = 'BLOG_NOT_FOUND';

    /** 不能给自己的博客点赞 / 收藏 / 拉黑。HTTP 403 */
    public const BLOG_SELF_REACTION = 'BLOG_SELF_REACTION';

    /** 发布时缺封面。草稿可以没有，发布必须有 —— 见模块文档决策 8。HTTP 422 */
    public const BLOG_COVER_REQUIRED = 'BLOG_COVER_REQUIRED';

    /** 评论不存在（已软删的同样按不存在处理）。HTTP 404 */
    public const COMMENT_NOT_FOUND = 'COMMENT_NOT_FOUND';

    /** 评论目标类型不支持。本期只认 `blog`；论坛落地时加 `forum_post`。HTTP 422 */
    public const COMMENT_TARGET_INVALID = 'COMMENT_TARGET_INVALID';

    /** 父评论与当前目标不匹配（不允许把子树挂到另一个对象上）。HTTP 422 */
    public const COMMENT_PARENT_MISMATCH = 'COMMENT_PARENT_MISMATCH';

    /** 不支持的上传文件类型。HTTP 422 */
    public const UPLOAD_KIND_UNSUPPORTED = 'UPLOAD_KIND_UNSUPPORTED';

    /**
     * 错误码 → HTTP 状态码。
     *
     * 用法：
     *   ErrorCode::httpStatus(ErrorCode::LICENSE_EXPIRED); // 403
     *
     * 边界/注意：
     *   未登记的 code 一律按 500 处理 —— 这是刻意的，防止新增 code 时忘了登记却表现为成功。
     *
     * @var array<string, int>
     */
    private const HTTP_STATUS = [
        self::OK => 200,

        self::SYS_INTERNAL => 500,
        self::SYS_VALIDATION => 422,
        self::SYS_NOT_FOUND => 404,
        self::SYS_METHOD_NOT_ALLOWED => 405,
        self::SYS_UNAUTHENTICATED => 401,
        self::SYS_FORBIDDEN => 403,
        self::SYS_RATE_LIMITED => 429,

        self::AUTH_INVALID_CREDENTIALS => 401,
        self::AUTH_ACCOUNT_DISABLED => 403,

        self::LICENSE_INVALID => 403,
        self::LICENSE_EXPIRED => 403,
        self::LICENSE_PRODUCT_UNKNOWN => 404,
        self::LICENSE_MACHINE_MISMATCH => 403,

        self::CLIENT_SIGN_INVALID => 401,
        self::CLIENT_TIMESTAMP_OUT_OF_WINDOW => 401,
        self::CLIENT_NONCE_REPLAYED => 401,

        self::CONTENT_NOT_FOUND => 404,

        self::BLOG_NOT_FOUND => 404,
        self::BLOG_SELF_REACTION => 403,
        self::BLOG_COVER_REQUIRED => 422,
        self::COMMENT_NOT_FOUND => 404,
        self::COMMENT_TARGET_INVALID => 422,
        self::COMMENT_PARENT_MISMATCH => 422,
        self::UPLOAD_KIND_UNSUPPORTED => 422,
    ];

    /**
     * 错误码 → 默认中文文案。
     *
     * 用法：
     *   ErrorCode::message(ErrorCode::SYS_UNAUTHENTICATED); // '未登录或令牌无效'
     *
     * 边界/注意：
     *   这是**兜底文案**，只用于开发期与日志排查。
     *   API 的 message 字段将来要国际化（走 lang/），客户端不应该拿它做判断。
     *
     * @var array<string, string>
     */
    private const MESSAGES = [
        self::OK => '成功',

        self::SYS_INTERNAL => '服务器内部错误',
        self::SYS_VALIDATION => '参数校验失败',
        self::SYS_NOT_FOUND => '请求的资源不存在',
        self::SYS_METHOD_NOT_ALLOWED => '请求方法不被允许',
        self::SYS_UNAUTHENTICATED => '未登录或令牌无效',
        self::SYS_FORBIDDEN => '没有权限执行此操作',
        self::SYS_RATE_LIMITED => '请求过于频繁，请稍后再试',

        self::AUTH_INVALID_CREDENTIALS => '账号或密码不正确',
        self::AUTH_ACCOUNT_DISABLED => '账号已被禁用',

        self::LICENSE_INVALID => '授权码无效',
        self::LICENSE_EXPIRED => '授权已过期',
        self::LICENSE_PRODUCT_UNKNOWN => '未知的产品标识',
        self::LICENSE_MACHINE_MISMATCH => '授权与当前设备不匹配',

        self::CLIENT_SIGN_INVALID => '签名校验不通过',
        self::CLIENT_TIMESTAMP_OUT_OF_WINDOW => '请求时间超出允许范围',
        self::CLIENT_NONCE_REPLAYED => '请求已被处理过',

        self::CONTENT_NOT_FOUND => '内容不存在',

        self::BLOG_NOT_FOUND => '博客不存在或已不可见',
        self::BLOG_SELF_REACTION => '不能对自己的博客进行该操作',
        self::BLOG_COVER_REQUIRED => '发布前需要先上传封面',
        self::COMMENT_NOT_FOUND => '评论不存在',
        self::COMMENT_TARGET_INVALID => '评论目标类型不支持',
        self::COMMENT_PARENT_MISMATCH => '父评论不属于当前对象',
        self::UPLOAD_KIND_UNSUPPORTED => '不支持的文件类型',
    ];

    /**
     * 取错误码对应的 HTTP 状态码。
     *
     * 用法：
     *   ErrorCode::httpStatus(ErrorCode::SYS_VALIDATION); // 422
     *   ErrorCode::httpStatus('不存在的码');              // 500（未登记按内部错误处理）
     *
     * @param  string  $code  错误码常量之一
     * @return int HTTP 状态码
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public static function httpStatus(string $code): int
    {
        return self::HTTP_STATUS[$code] ?? 500;
    }

    /**
     * 取错误码的默认中文文案。
     *
     * 用法：
     *   ErrorCode::message(ErrorCode::LICENSE_EXPIRED); // '授权已过期'
     *
     * 边界/注意：
     *   未登记的 code 返回通用文案，而不是抛异常 —— 出参构造过程中不应该再抛错。
     *
     * @param  string  $code  错误码常量之一
     * @return string 中文文案
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    国际化时改为从 lang/ 读取（按 Accept-Language）
     */
    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? '请求失败';
    }

    /**
     * 判断某个错误码是否已登记（有 HTTP 状态映射）。
     *
     * 用法：
     *   ErrorCode::has('LICENSE_EXPIRED'); // true
     *   ErrorCode::has('随便编的');         // false
     *
     * @param  string  $code  待检查的错误码
     * @return bool 已登记返回 true
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public static function has(string $code): bool
    {
        return isset(self::HTTP_STATUS[$code]);
    }
}
