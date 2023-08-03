<?php
/**
 * Author: Jayin Taung <tonjayin@gmail.com>
 */

namespace app\qiniu\model;

use think\Model;

class QiniuUploadFileModel extends Model
{
    protected $name = 'qiniu_upload_file';

    /**
     * 文件状态:正常，启用
     */
    const FILE_STATUS_OK = 0;
    /**
     * 文件状态:禁用
     */
    const FILE_STATUS_DISABLE = 1;
}