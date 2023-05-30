<?php
/**
 * Author: Jayin Taung <tonjayin@gmail.com>
 */

return [
    'sence' => 'default',
    'sences' => [
        'default' => [
            'access_key' => 'xxx',
            'secret_key' => 'xxx',
            'bucket' => 'xxx',
            'upload' => [
                // 允许的附件后缀【注：这里主要是用于前端判定】
                'allow_suffix' => 'pdf,doc,docx,xls,xlsx,ppt,pptx',
                'prefix_key' => 'd/',
                // 限定上传文件大小最大值，单位Byte。超过限制上传文件大小的最大值会被判为上传失败，返回 413 状态码。
                'fsizeLimit' => 10 * 1024 * 1024 ,// 10MB
            ],
        ]
    ]
];