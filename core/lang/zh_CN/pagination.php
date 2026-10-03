<?php

declare(strict_types=1);

/**
 * 分页提示（简体中文）
 *
 * 本文件属于 core（纯 API 后端）的 Support 层。
 * 用途：分页器链接文案中文化。**现在还用不到**（分页接口尚未实现），
 *       但 `ApiResponse::paginate()` 一旦落地就会用到，先备着。
 * 谁在调：Laravel 的 Paginator。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/modules/00-support.md
 */

return [

    'previous' => '&laquo; 上一页',
    'next' => '下一页 &raquo;',

];
