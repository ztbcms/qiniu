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
                'domain' => env('qiniu.domain', request()->domain()),
                'prefix_key' => env('qiniu.fetch_prefix_key', 'fetch/'),
            ]
        ]
    ]
];