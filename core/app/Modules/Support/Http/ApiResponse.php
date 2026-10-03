<?php

declare(strict_types=1);

/**
 * ApiResponse —— 统一 API 响应体
 *
 * 本文件属于 core（纯 API 后端）的 Support 模块。
 * 用途：**所有**接口的出参都必须经这里构造，保证 face / admin / EA 三方拿到同一种结构。
 *       各模块不要自己 `response()->json([...])` 拼数组。
 * 谁在调：所有 Controller、全局异常处理器（bootstrap/app.php）。
 *
 * 响应结构（固定四字段，缺一不可）：
 *   {
 *     "code":    "OK",            // 机器可读，见 ErrorCode；客户端按它分支
 *     "message": "成功",           // 人读文案，将来国际化；客户端不要用它判断
 *     "data":    { ... } | null,  // 成功时为业务数据，失败时为 null
 *     "meta":    { ... }          // 附加信息：分页、错误上下文等。永远是对象
 *   }
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/00-support.md
 */

namespace App\Modules\Support\Http;

use App\Modules\Support\ErrorCode;
use Illuminate\Http\JsonResponse;
use stdClass;

final class ApiResponse
{
    /**
     * JSON 编码选项：中文不转义、斜杠不转义。
     *
     * 用法：
     *   response()->json($payload, 200, [], self::JSON_OPTIONS);
     *
     * 边界/注意：
     *   默认的 `json_encode` 会把中文转成 `\uXXXX`（合法 JSON，但字节数翻倍、
     *   日志不可读、人工排查困难）。这两个选项只影响编码形态，不影响语义。
     *
     * @var int
     */
    private const JSON_OPTIONS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * 构造成功响应。
     *
     * 用法：
     *   return ApiResponse::ok(['id' => 1, 'title' => '标题']);
     *   return ApiResponse::ok($posts, ['page' => 1, 'total' => 42]);  // 分页放 meta
     *
     * 边界/注意：
     *   1. `$data` 传空数组时会输出 `{}` 而不是 `[]` —— 客户端可以无条件按对象取字段，
     *      不会因为"这次没有数据"就拿到数组而解析失败。要输出列表请直接传列表数组。
     *   2. `$meta` 只放**附加**信息（分页、游标）。业务数据一律放 `$data`，
     *      不要为了省一层把业务字段塞进 meta。
     *
     * @param  array<array-key, mixed>  $data  业务数据，默认空对象
     * @param  array<string, mixed>  $meta  附加信息（分页等），默认空
     * @return JsonResponse HTTP 200
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    补 request_id 进 meta，便于和审计日志对齐
     */
    public static function ok(array $data = [], array $meta = []): JsonResponse
    {
        return response()->json([
            'code' => ErrorCode::OK,
            'message' => ErrorCode::message(ErrorCode::OK),
            'data' => $data === [] ? new stdClass : $data,
            'meta' => $meta === [] ? new stdClass : $meta,
        ], ErrorCode::httpStatus(ErrorCode::OK), [], self::JSON_OPTIONS);
    }

    /**
     * 构造失败响应。
     *
     * 用法：
     *   return ApiResponse::fail(ErrorCode::LICENSE_EXPIRED);
     *   return ApiResponse::fail(ErrorCode::SYS_VALIDATION, ['errors' => $e->errors()]);
     *   return ApiResponse::fail(ErrorCode::SYS_FORBIDDEN, [], '这个操作需要管理员权限');
     *
     * 边界/注意：
     *   HTTP 状态码由 `ErrorCode::httpStatus()` 决定，**不要**在这里传状态码 ——
     *   错误码与状态码的对应关系只在 ErrorCode 一处维护，否则两边会漂移。
     *   未登记的错误码会得到一个 500，这是刻意的（见 ErrorCode 注释）。
     *
     * @param  string  $code  错误码，必须是 `ErrorCode` 里的常量
     * @param  array<string, mixed>  $meta  错误上下文（如校验失败明细放 ['errors' => ...]）
     * @param  string|null  $message  覆盖默认文案；传 null 用 ErrorCode 的默认值
     * @return JsonResponse 状态码由错误码决定
     *
     * @version 0.1.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public static function fail(string $code, array $meta = [], ?string $message = null): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $message ?? ErrorCode::message($code),
            'data' => null,
            'meta' => $meta === [] ? new stdClass : $meta,
        ], ErrorCode::httpStatus($code), [], self::JSON_OPTIONS);
    }
}
