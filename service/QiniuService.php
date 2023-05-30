<?php
/**
 * Author: Jayin Taung <tonjayin@gmail.com>
 */

namespace app\qiniu\service;

use Qiniu\Auth;
use think\Exception;
use think\facade\Config;

class QiniuService
{
    private $sence = '';

    public function __construct($sence = 'default')
    {
        $this->sence = $sence;
    }

    /**
     * @return mixed|string
     */
    public function getSence()
    {
        return $this->sence;
    }

    /**
     * @param mixed|string $sence
     */
    public function setSence($sence): QiniuService
    {
        $this->sence = $sence;
        return $this;
    }


    function config()
    {
        if (!Config::has('qiniu')) {
            Config::load(base_path() . 'qiniu/config/qiniu.php', 'qiniu');
        }
        throw_if(empty(config('qiniu.sences')[$this->sence]), new Exception('Not Found sence:' . $this->sence));
        return config('qiniu.sences')[$this->sence];
    }

    function getUploadToken($key = null, $expires = 3600, $policy = null, $strictPolicy = true)
    {
        $config = $this->config();
        $auth = new Auth($config['access_key'], $config['secret_key']);
        return $auth->uploadToken($config['bucket'], $key, $expires, $policy, $strictPolicy);
    }
}