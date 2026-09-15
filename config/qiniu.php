<?php
/**
 * Author: Jayin Taung <tonjayin@gmail.com>
 */

return [
    'sence' => 'default',
    'sences' => [
        'default' => [
            'access_key' => env('qiniu.access_key', ''),
            'secret_key' => env('qiniu.secret_key', ''),
            'bucket' => env('qiniu.bucket', ''),
            'upload' => [
                'domain' => env('qiniu.domain', request()->domain()),
                // 允许的附件后缀【注：这里主要是用于前端判定】
                'allow_suffix' => env('qiniu.upload_allow_suffix', 'pdf,doc,docx,xls,xlsx,ppt,pptx'),
                'prefix_key' => env('qiniu.upload_prefix_key', 'd/'), // 不要/开头，要/结尾
                // 限定上传文件大小最大值，单位Byte。超过限制上传文件大小的最大值会被判为上传失败，返回 413 状态码。
                'size_limit' => intval(env('qiniu.upload_size_limit', 10 * 1024 * 1024)),// 10MB
            ],
            'fetch' => [
                // 无任务 ID 时的提交确认宽限期（秒），到期且对象不存在则允许重试。
                'submit_confirm_timeout' => intval(env('qiniu.fetch_submit_confirm_timeout', 30)),
                // 可选。配置后必须为公网 HTTPS 完整地址，如 https://api.example.com/qiniu/fetch/callback。
                // 未配置时不向七牛发送 callbackurl，仅通过 stat/排队信息确认。
                'callback_url' => env('qiniu.fetch_callback_url', ''),
                'domain' => env('qiniu.domain', request()->domain()),
                'prefix_key' => env('qiniu.fetch_prefix_key', 'fetch/'),
            ]
        ]
    ]
];
