<?php
/**
 * Author: Jayin Taung <tonjayin@gmail.com>
 */

namespace app\qiniu\libs;

class StringUtils
{
    /**
     * 通过URL获取资源的文件名
     * @param $url
     * @return mixed|string
     */
    static function getFileNameByURL($url)
    {
        $_arr = explode('/', $url);
        $_arr = explode('?', $_arr[count($_arr) - 1]);
        return $_arr[0];
    }

    /**
     * 根据文件名获取文件后缀
     * @param $file_name
     * @return mixed|string
     */
    static function getFileExtByFileName($file_name)
    {
        $_arr = explode('.', $file_name);
        if (count($_arr) > 1) {
            return $_arr[count($_arr) - 1];
        }
        return '';
    }
}