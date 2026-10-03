<?php

declare(strict_types=1);

/**
 * ResolvesOptionalMember —— 在**公开接口**里"尽力"识别当前会员
 *
 * 本文件属于 core（纯 API 后端）的 Blog 模块。
 * 用途：给那些"不需要登录、但登录了就该有更好体验"的接口用
 *       （博客列表 / 详情 / 评论树 —— 它们都要回显 `mine`：我点过赞没）。
 * 谁在用：PublicBlogController、CommentController。
 *
 * 为什么需要它（而不是直接 `$request->user('member')`）：
 *   公开接口上没有 `auth:member` 中间件。此时 `$request->user('member')` 会
 *   **自己去解析请求里的令牌** —— 一旦令牌过期或伪造，它会抛认证异常。
 *   那个异常如果在公开接口上冒出去，结果是：**一个坏令牌就能让所有人都看不到博客**。
 *   所以这里整块吞掉，退回"未登录"，公开内容照常出。
 *
 * @version 0.1.0
 * @since   2026-10-04
 * @see     docs/modules/10-blog.md
 */

namespace App\Modules\Blog\Http\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Throwable;

trait ResolvesOptionalMember
{
    /**
     * 取当前登录会员，取不到就返回 null。
     *
     * 用法：
     *   $viewer = $this->optionalMember($request);
     *   $mine = $viewer === null ? null : $reactions->mineOf($viewer, 'blog', $ids);
     *
     * 边界/注意：
     *   1. 只用于**公开接口**。已挂 `auth:member` 的路由直接用
     *      `$request->user('member')` 就好 —— 那里令牌无效本来就该 401，
     *      在这个 trait 里吞掉反而会把"没登录"变成"登录了但看不到"。
     *   2. 返回的是 `null` 而不是抛异常，调用方必须显式处理"未登录"这条分支。
     *
     * @param  Request  $request  请求
     * @return User|null  登录会员；未登录或令牌无效时为 null
     *
     * @version 0.1.0
     * @since   2026-10-04
     * @todo    无
     */
    protected function optionalMember(Request $request): ?User
    {
        try {
            return $request->user('member');
        } catch (Throwable) {
            return null;
        }
    }
}
