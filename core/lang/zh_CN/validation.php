<?php

declare(strict_types=1);

/**
 * 校验提示（简体中文）
 *
 * 本文件属于 core（纯 API 后端）的 Support 层 —— 全站 17 个模块共用。
 * 用途：把校验失败的 `message` 变成中文。`meta.errors` 里的**字段键不变**，
 *       所以前端仍然按 `username` / `password` 这种原始字段名定位输入框。
 * 谁在调：Laravel 的 Validator，经 `bootstrap/app.php` 统一转成 `SYS_VALIDATION`。
 *
 * ⚠️ 改这个文件的两条规矩：
 *   1. **键必须与 `lang/en/validation.php` 完全对齐。** 两边不一致时，缺失的键会
 *      掉回英文（`fallback_locale=en`），提示就变成半中半英。
 *      对照方式：`php artisan lang:publish` 更新英文文件后逐键核对。
 *   2. 只改文案，**不要改键名**。键名是 Laravel 规则名（`required` / `max` …），
 *      改了等于那个规则没有提示。
 *
 * @version 0.1.0
 *
 * @since   2026-10-03
 * @see     docs/standards.md
 */

return [

    /*
    |--------------------------------------------------------------------------
    | 校验提示
    |--------------------------------------------------------------------------
    |
    | `:attribute` 会被替换成字段的显示名（见文件末尾的 `attributes`），
    | 其余 `:xxx` 由具体的校验规则提供。
    |
    */

    'accepted' => ':attribute 必须接受。',
    'accepted_if' => ':other 为 :value 时，:attribute 必须接受。',
    'active_url' => ':attribute 不是有效的网址。',
    'after' => ':attribute 必须是一个 :date 之后的日期。',
    'after_or_equal' => ':attribute 必须是一个等于或晚于 :date 的日期。',
    'alpha' => ':attribute 只能由字母组成。',
    'alpha_dash' => ':attribute 只能由字母、数字、短横线和下划线组成。',
    'alpha_num' => ':attribute 只能由字母和数字组成。',
    'any_of' => ':attribute 无效。',
    'array' => ':attribute 必须是数组。',
    'array_keys' => ':attribute 只能包含以下键名：:values。',
    'ascii' => ':attribute 只能包含单字节的字母、数字和符号。',
    'base64' => ':attribute 必须是有效的 Base64 字符串。',
    'before' => ':attribute 必须是一个 :date 之前的日期。',
    'before_or_equal' => ':attribute 必须是一个等于或早于 :date 的日期。',
    'between' => [
        'array' => ':attribute 只能有 :min - :max 个项。',
        'file' => ':attribute 必须介于 :min - :max KB 之间。',
        'numeric' => ':attribute 必须介于 :min - :max 之间。',
        'string' => ':attribute 必须介于 :min - :max 个字符之间。',
    ],
    'boolean' => ':attribute 必须为布尔值。',
    'can' => ':attribute 含有未授权的值。',
    'confirmed' => ':attribute 两次输入不一致。',
    'contains' => ':attribute 缺少必需的值。',
    'current_password' => '密码不正确。',
    'date' => ':attribute 不是有效的日期。',
    'date_equals' => ':attribute 必须是等于 :date 的日期。',
    'date_format' => ':attribute 的格式必须为 :format。',
    'decimal' => ':attribute 必须是 :decimal 位小数。',
    'declined' => ':attribute 必须拒绝。',
    'declined_if' => ':other 为 :value 时，:attribute 必须拒绝。',
    'different' => ':attribute 和 :other 必须不同。',
    'digits' => ':attribute 必须是 :digits 位数字。',
    'digits_between' => ':attribute 必须是 :min - :max 位数字。',
    'dimensions' => ':attribute 的图片尺寸不符合要求。',
    'distinct' => ':attribute 存在重复的值。',
    'doesnt_contain' => ':attribute 不能包含以下内容：:values。',
    'doesnt_end_with' => ':attribute 不能以以下内容结尾：:values。',
    'doesnt_start_with' => ':attribute 不能以以下内容开头：:values。',
    'email' => ':attribute 不是有效的邮箱地址。',
    'encoding' => ':attribute 必须使用 :encoding 编码。',
    'ends_with' => ':attribute 必须以以下内容之一结尾：:values。',
    'enum' => '所选的 :attribute 无效。',
    'exists' => '所选的 :attribute 无效。',
    'extensions' => ':attribute 的扩展名必须是以下之一：:values。',
    'file' => ':attribute 必须是一个文件。',
    'filled' => ':attribute 不能为空。',
    'gt' => [
        'array' => ':attribute 必须多于 :value 个项。',
        'file' => ':attribute 必须大于 :value KB。',
        'numeric' => ':attribute 必须大于 :value。',
        'string' => ':attribute 必须多于 :value 个字符。',
    ],
    'gte' => [
        'array' => ':attribute 必须有 :value 个项或更多。',
        'file' => ':attribute 必须大于或等于 :value KB。',
        'numeric' => ':attribute 必须大于或等于 :value。',
        'string' => ':attribute 必须不少于 :value 个字符。',
    ],
    'hex_color' => ':attribute 必须是有效的十六进制颜色值。',
    'image' => ':attribute 必须是图片。',
    'in' => '所选的 :attribute 无效。',
    'in_array' => ':attribute 必须存在于 :other 中。',
    'in_array_keys' => ':attribute 必须至少包含以下键名之一：:values。',
    'integer' => ':attribute 必须是整数。',
    'ip' => ':attribute 必须是有效的 IP 地址。',
    'ipv4' => ':attribute 必须是有效的 IPv4 地址。',
    'ipv6' => ':attribute 必须是有效的 IPv6 地址。',
    'json' => ':attribute 必须是有效的 JSON 字符串。',
    'list' => ':attribute 必须是列表。',
    'lowercase' => ':attribute 必须是小写。',
    'lt' => [
        'array' => ':attribute 必须少于 :value 个项。',
        'file' => ':attribute 必须小于 :value KB。',
        'numeric' => ':attribute 必须小于 :value。',
        'string' => ':attribute 必须少于 :value 个字符。',
    ],
    'lte' => [
        'array' => ':attribute 不能多于 :value 个项。',
        'file' => ':attribute 必须小于或等于 :value KB。',
        'numeric' => ':attribute 必须小于或等于 :value。',
        'string' => ':attribute 不能多于 :value 个字符。',
    ],
    'mac_address' => ':attribute 必须是有效的 MAC 地址。',
    'max' => [
        'array' => ':attribute 不能多于 :max 个项。',
        'file' => ':attribute 不能大于 :max KB。',
        'numeric' => ':attribute 不能大于 :max。',
        'string' => ':attribute 不能多于 :max 个字符。',
    ],
    'max_digits' => ':attribute 不能多于 :max 位数字。',
    'mimes' => ':attribute 必须是 :values 格式的文件。',
    'mimetypes' => ':attribute 必须是 :values 格式的文件。',
    'min' => [
        'array' => ':attribute 至少要有 :min 个项。',
        'file' => ':attribute 不能小于 :min KB。',
        'numeric' => ':attribute 不能小于 :min。',
        'string' => ':attribute 不能少于 :min 个字符。',
    ],
    'min_digits' => ':attribute 不能少于 :min 位数字。',
    'missing' => ':attribute 必须缺失。',
    'missing_if' => ':other 为 :value 时，:attribute 必须缺失。',
    'missing_unless' => ':other 不为 :value 时，:attribute 必须缺失。',
    'missing_with' => ':values 存在时，:attribute 必须缺失。',
    'missing_with_all' => ':values 都存在时，:attribute 必须缺失。',
    'multiple_of' => ':attribute 必须是 :value 的倍数。',
    'not_in' => '所选的 :attribute 无效。',
    'not_regex' => ':attribute 的格式无效。',
    'numeric' => ':attribute 必须是数字。',
    'password' => [
        'letters' => ':attribute 必须包含至少一个字母。',
        'mixed' => ':attribute 必须同时包含大写和小写字母。',
        'numbers' => ':attribute 必须包含至少一个数字。',
        'symbols' => ':attribute 必须包含至少一个符号。',
        'uncompromised' => '这个 :attribute 曾出现在数据泄露事件中，请换一个。',
    ],
    'present' => ':attribute 必须存在。',
    'present_if' => ':other 为 :value 时，:attribute 必须存在。',
    'present_unless' => ':other 不为 :value 时，:attribute 必须存在。',
    'present_with' => ':values 存在时，:attribute 必须存在。',
    'present_with_all' => ':values 都存在时，:attribute 必须存在。',
    'prohibited' => ':attribute 被禁止。',
    'prohibited_if' => ':other 为 :value 时，:attribute 被禁止。',
    'prohibited_if_accepted' => ':other 接受时，:attribute 被禁止。',
    'prohibited_if_declined' => ':other 拒绝时，:attribute 被禁止。',
    'prohibited_unless' => ':other 属于 :values 时，:attribute 被禁止。',
    'prohibits' => ':attribute 禁止 :other 存在。',
    'regex' => ':attribute 的格式无效。',
    'required' => ':attribute 不能为空。',
    'required_array_keys' => ':attribute 必须包含以下条目：:values。',
    'required_if' => ':other 为 :value 时，:attribute 不能为空。',
    'required_if_accepted' => ':other 接受时，:attribute 不能为空。',
    'required_if_declined' => ':other 拒绝时，:attribute 不能为空。',
    'required_unless' => ':other 不为 :values 时，:attribute 不能为空。',
    'required_with' => ':values 存在时，:attribute 不能为空。',
    'required_with_all' => ':values 都存在时，:attribute 不能为空。',
    'required_without' => ':values 不存在时，:attribute 不能为空。',
    'required_without_all' => ':values 都不存在时，:attribute 不能为空。',
    'same' => ':attribute 和 :other 必须一致。',
    'size' => [
        'array' => ':attribute 必须包含 :size 个项。',
        'file' => ':attribute 必须是 :size KB。',
        'numeric' => ':attribute 必须是 :size。',
        'string' => ':attribute 必须是 :size 个字符。',
    ],
    'starts_with' => ':attribute 必须以以下内容之一开头：:values。',
    'string' => ':attribute 必须是字符串。',
    'timezone' => ':attribute 必须是有效的时区。',
    'unique' => ':attribute 已存在。',
    'uploaded' => ':attribute 上传失败。',
    'uppercase' => ':attribute 必须是大写。',
    'url' => ':attribute 必须是有效的网址。',
    'ulid' => ':attribute 必须是有效的 ULID。',
    'uuid' => ':attribute 必须是有效的 UUID。',

    /*
    |--------------------------------------------------------------------------
    | 指定字段的专属提示
    |--------------------------------------------------------------------------
    |
    | 形如 `'字段名.规则名' => '提示'`，用来覆盖上面的通用提示。
    | 只在"通用提示说不清楚"时才加，别把通用提示一条条重抄一遍。
    |
    */

    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | 字段显示名
    |--------------------------------------------------------------------------
    |
    | 把 `email` 这种字段名换成「邮箱」。**全站共用这一张表**，
    | 所以像 `username` / `password` 这种到处出现的字段只在这里定义一次。
    |
    | 某个模块需要特殊叫法时，在那个模块的 FormRequest 里写 `attributes()`
    | 覆盖即可（就近优先），**不要为了一个模块的需求改这张全站表**。
    |
    */

    'attributes' => [
        'username' => '账号',
        'password' => '密码',
        'password_confirmation' => '确认密码',
        'email' => '邮箱',
        'name' => '名称',
        'phone' => '手机号',
        'code' => '验证码',
        'device_name' => '设备名',
        'status' => '状态',
        'title' => '标题',
        'content' => '内容',
        'remark' => '备注',
    ],

];
