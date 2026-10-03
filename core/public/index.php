<?php

declare(strict_types=1);

/**
 * API 入口
 *
 * ⚠️ 这里的 `ini_set('display_errors', '0')` 是**故意的，不要删**。
 *
 * 为什么必须有：
 *   纯 API 的响应体只能是 `{code, message, data, meta}` 这一个骨架。
 *   而 PHP 的 warning / deprecated 是**直接往输出流里写**的 —— 只要 `display_errors` 开着，
 *   它们就会出现在 JSON **前面**，前端 `JSON.parse` 立刻炸，报的却是"服务不可用"这种误导性错误。
 *
 *   本项目真实踩过：PHP 8.4.26 下 `symfony/finder` 的返回类型弃用警告就干过这事 ——
 *   接口全部返回 `<br /><b>Deprecated</b>: …` 开头的 HTML，而 HTTP 状态码还是 200。
 *
 * 为什么关它不会影响排查：
 *   `display_errors` 只管"**显示**"，不管"**记录**"。警告照样进日志（`storage/logs/`）。
 *   而 `APP_DEBUG=true` 时的详细报错页是 Laravel 异常处理器渲染的，**不走 display_errors**，
 *   所以开发体验一点没丢。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

ini_set('display_errors', '0');

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
