<?php

declare(strict_types=1);

/**
 * 应用服务提供者清单
 *
 * 说明：只有**需要注册容器绑定 / 启动动作**的模块才列在这里。
 *       Support 模块全是静态方法（ApiResponse / ErrorCode / ApiException），
 *       没有绑定需求，所以不占一个 Provider。
 *
 * 新增模块时：先建 `app/Modules/<Name>/<Name>ServiceProvider.php`，
 *            再把它加进下面的数组。
 */

use App\Modules\Crypto\CryptoServiceProvider;
use App\Modules\Rbac\RbacServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    CryptoServiceProvider::class,
    RbacServiceProvider::class,
];
