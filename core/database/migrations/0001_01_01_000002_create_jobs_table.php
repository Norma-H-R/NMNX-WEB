<?php

declare(strict_types=1);

/**
 * 建 jobs / job_batches / failed_jobs 三张表（Laravel 骨架 + 本项目补齐的列备注）
 *
 * 本项目的队列现状（**别被"表已建好"误导**）：
 *   `QUEUE_CONNECTION=database`，三张表也就位了，但**目前没有起 `queue:work`**，
 *   而且还没有任何真正的异步任务 —— 所以这三张表现在都是空的。
 *   第一个异步任务（发邮件、导出、生成报表）落地时**必须同时起 worker**，
 *   否则任务会安安静静堆在 `jobs` 里，谁也不执行。
 *
 * ⚠️ 关于备注：SQLite 不支持列备注，`->comment()` 会被忽略（换 MySQL 才是真备注）。
 *    现在就能查的字段说明见 `docs/database.md`。
 *
 * @version 0.2.0
 *
 * @since   2026-10-03
 * @see     docs/database.md
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 执行迁移。
     *
     * 用法：
     *   php artisan migrate
     *
     * 边界/注意：
     *   时间列在这里多数是 **Unix 秒（unsignedInteger）**而不是 datetime ——
     *   这是队列表的历史设计（比较便宜、不需要时区转换），别顺手改成 timestamp。
     *
     *
     * @version 0.2.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id()->comment('自增主键。`queue:work` 按它取任务');
            $table->string('queue')->index()->comment('队列名（default / mail / exports…）。worker 按它决定取哪条');
            $table->longText('payload')->comment('任务载荷（序列化后的任务对象 + 数据）');
            $table->unsignedSmallInteger('attempts')->comment('已尝试次数。超过 task 的 tries 就丢进 failed_jobs');
            $table->unsignedInteger('reserved_at')->nullable()->comment('被 worker 取走的时间（Unix 秒）；null = 还在等');
            $table->unsignedInteger('available_at')->comment('最早可执行时间（Unix 秒）。延时任务靠它');
            $table->unsignedInteger('created_at')->comment('入队时间（Unix 秒）');
        });

        Schema::create('job_batches', function (Blueprint $table): void {
            $table->string('id')->primary()->comment('批次 ID（UUID）');
            $table->string('name')->comment('批次名。`Bus::batch()->name()` 给的');
            $table->integer('total_jobs')->comment('批次总任务数');
            $table->integer('pending_jobs')->comment('还没跑完的任务数。归零即批次结束');
            $table->integer('failed_jobs')->comment('失败任务数。>0 时可触发 catch 回调');
            $table->longText('failed_job_ids')->comment('失败任务的 ID 列表（序列化数组）');
            $table->mediumText('options')->nullable()->comment('批次的回调配置（then / catch / finally）');
            $table->integer('cancelled_at')->nullable()->comment('取消时间（Unix 秒）；null = 未取消');
            $table->integer('created_at')->comment('创建时间（Unix 秒）');
            $table->integer('finished_at')->nullable()->comment('结束时间（Unix 秒）；null = 还在跑');
        });

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id()->comment('自增主键');
            $table->string('uuid')->unique()->comment('失败记录 UUID。`queue:retry <uuid>` 用它重试');
            $table->string('connection')->comment('队列连接名（本项目是 database）');
            $table->string('queue')->comment('队列名');
            $table->longText('payload')->comment('任务载荷原文。重试时直接放回 jobs');
            $table->longText('exception')->comment('异常堆栈全文。⚠️ 可能有敏感数据（SQL、路径），别整份贴到工单里');
            $table->timestamp('failed_at')->useCurrent()->comment('失败时间');

            $table->index(['connection', 'queue', 'failed_at']);
        });
    }

    /**
     * 回滚迁移。
     *
     * 用法：
     *   php artisan migrate:rollback
     *
     * 边界/注意：
     *   回滚会**丢掉所有未执行的任务与失败记录**。生产环境回滚前先确认队列是空的。
     *
     *
     * @version 0.2.0
     *
     * @since   2026-10-03
     *
     * @todo    无
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
