<?php
/**
 * Author: Jayin Taung <tonjayin@gmail.com>
 */

namespace app\qiniu\model;

use think\Model;

class QiniuFetchFileModel extends Model
{
    protected $name = 'qiniu_fetch_file';

    // 拉取状态0拉取中，1拉取完成2拉取失败
    const FETCH_STATUS_DOING = 0;
    const FETCH_STATUS_DONE = 1;
    const FETCH_STATUS_FAILD = 2;
}