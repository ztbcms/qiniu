<?php
/**
 * Author: Jayin Taung <tonjayin@gmail.com>
 */

namespace app\qiniu\service;

use app\common\service\BaseService;
use app\qiniu\model\QiniuUploadFileModel;
use Qiniu\Auth;
use think\Exception;
use think\facade\Config;

/**
 * 七牛服务
 */
class QiniuService extends BaseService
{
    private $sence = '';

    // 标识资源不存在的状态码
    private const STATUS_CODE_NO_EXIST = [404, 612];

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

    private function getAuth()
    {

    }

    function getUploadToken($key = null, $expires = 3600, $policy = null, $strictPolicy = true)
    {
        $config = $this->config();
        $auth = new Auth($config['access_key'], $config['secret_key']);
        return $auth->uploadToken($config['bucket'], $key, $expires, $policy, $strictPolicy);
    }

    /**
     * 删除七牛云上的资源
     * @param QiniuUploadFileModel $fileModel
     * @return array
     */
    function doDeleteFile(QiniuUploadFileModel $fileModel)
    {
        $config = $this->config();
        $auth = new Auth($config['access_key'], $config['secret_key']);
        $bucketManager = new \Qiniu\Storage\BucketManager($auth);
        list($data, $err) = $bucketManager->delete($fileModel->bucket, $fileModel->key);
        if ($err) {
            // 资源找不到，说明已删除了
            if (in_array($err->code(), self::STATUS_CODE_NO_EXIST)) {
                return self::createReturn(true, null, '资源不存在');
            }
            return self::createReturn(false, null, $err->message());
        }
        return self::createReturn(true, $data, '操作成功');
    }

    /**
     * 删除文件记录和七牛云上资源
     * @param $file_uuid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    function deleteFile($file_uuid)
    {
        $fileModel = QiniuUploadFileModel::where('uuid', '=', $file_uuid)->find();
        if (!$fileModel) {
            return self::createReturn(false, null, '记录不存在');
        }
        $res = $this->doDeleteFile($fileModel);
        if (!$res['status']) {
            return $res;
        }
        $fileModel->delete();
        return self::createReturn(true, null, '操作完成');
    }

    /**
     * 设置七牛云上资源启用状态
     * 本接口用于修改文件的存储状态，即禁用和启用状态间的的互相转换。
     * 处于禁用状态的文件将只能通过签发 Token 的方式访问 下载凭证。
     * @see https://developer.qiniu.com/kodo/4173/modify-the-file-status
     * @param QiniuUploadFileModel $fileModel
     * @param $status int 值为数字，0表示启用，1表示禁用
     * @return array
     */
    function doSetFileStatus(QiniuUploadFileModel $fileModel, $status)
    {
        $config = $this->config();
        $auth = new Auth($config['access_key'], $config['secret_key']);
        $bucketManager = new \Qiniu\Storage\BucketManager($auth);
        list($data, $err) = $bucketManager->changeStatus($fileModel->bucket, $fileModel->key, intval($status));
        if ($err) {
            return self::createReturn(false, null, $err->message());
        }
        return self::createReturn(true, $data, '操作成功');
    }

    /**
     * 设置文件启用状态
     * @param $file_uuid
     * @param $is_block
     * @return array
     */
    function setFileStatus($file_uuid, $status)
    {
        $fileModel = QiniuUploadFileModel::where('uuid', '=', $file_uuid)->find();
        if (!$fileModel) {
            return self::createReturn(false, null, '找不到文件');
        }
        $status = intval($status);
        if (!in_array($status, [0, 1])) {
            return self::createReturn(false, null, '参数异常：status');
        }
        $res = $this->doSetFileStatus($fileModel, $status);
        if (!$res['status']) {
            return $res;
        }
        $fileModel->save([
            'file_status' => $status
        ]);
        return self::createReturn(true, null, '操作完成');
    }
}