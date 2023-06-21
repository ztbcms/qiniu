<?php
/**
 * Author: Jayin Taung <tonjayin@gmail.com>
 */

namespace app\qiniu\service;

use app\common\service\BaseService;
use app\qiniu\libs\StringUtils;
use app\qiniu\model\QiniuFetchFileModel;
use app\qiniu\model\QiniuUploadFileModel;
use Qiniu\Auth;
use Qiniu\Storage\BucketManager;
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

    /**
     * 获取全部配置
     * @return mixed
     * @throws \Throwable
     */
    function config()
    {
        if (!Config::has('qiniu')) {
            Config::load(base_path() . 'qiniu/config/qiniu.php', 'qiniu');
        }
        throw_if(empty(config('qiniu.sences')[$this->sence]), new Exception('Not Found sence:' . $this->sence));
        return config('qiniu.sences')[$this->sence];
    }

    /**
     * 获取配置值
     * @param $key
     * @return mixed
     * @throws \Throwable
     */
    function getConfig($key)
    {
        return $this->config()[$key];
    }

    /**
     * @return Auth
     */
    private function getAuth()
    {
        $config = $this->config();
        return new Auth($config['access_key'], $config['secret_key']);
    }

    /**
     * 获取上传凭证
     * @param $key
     * @param $expires
     * @param $policy
     * @param $strictPolicy
     * @return string
     * @throws \Throwable
     */
    function getUploadToken($key = null, $expires = 3600, $policy = null, $strictPolicy = true)
    {
        $config = $this->config();
        $auth = $this->getAuth();
        return $auth->uploadToken($config['bucket'], $key, $expires, $policy, $strictPolicy);
    }

    /**
     * 删除七牛云上的资源
     * @param QiniuUploadFileModel $fileModel
     * @return array
     */
    function doDeleteFile($bucket, $key)
    {
        $auth = $this->getAuth();
        $bucketManager = new \Qiniu\Storage\BucketManager($auth);
        list($data, $err) = $bucketManager->delete($bucket, $key);
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
        $res = $this->doDeleteFile($fileModel->bucket, $fileModel->key);
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
    function doSetFileStatus($bucket, $key, $status)
    {
        $auth = $this->getAuth();
        $bucketManager = new \Qiniu\Storage\BucketManager($auth);
        list($data, $err) = $bucketManager->changeStatus($bucket, $key, intval($status));
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
        $res = $this->doSetFileStatus($fileModel->bucket, $fileModel->key, $status);
        if (!$res['status']) {
            return $res;
        }
        $fileModel->save([
            'file_status' => $status
        ]);
        return self::createReturn(true, null, '操作完成');
    }

    /**
     * 发起抓取
     * @param $key
     * @param $url
     * @return array
     * @throws \Throwable
     */
    function doFetchFile($bucket, $key, $url)
    {
        $auth = $this->getAuth();
        $bucketManager = new BucketManager($auth);
        // 异步抓取
        list($ret, $err) = $bucketManager->asynchFetch($url, $bucket, null, $key);
        if ($err) {
            return self::createReturn(false, null, $err->message());
        }
        return self::createReturn(true, $ret, '已提交抓取任务');
    }

    /**
     * 创建抓取
     * @param $url
     * @param $key
     * @param $file_name
     * @return array
     * @throws \Throwable
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    function createFetch($url, $key = '', $file_name = '')
    {
        // Check params
        if (empty($file_name)) {
            $file_name = StringUtils::getFileNameByURL($url);
        }
        $file_ext = StringUtils::getFileExtByFileName($file_name);
        if (empty($key)) {
            $key = $this->getConfig('fetch')['prefix_key'] . strtolower(md5($url)) . '.' . $file_ext;

        }
        $uuid = generateUniqueId();
        $bucket = $this->getConfig('bucket');
        $exist = QiniuFetchFileModel::where([
            'bucket' => $bucket,
            'key' => $key,
        ])->field('id,bucket,key')->find();
        if ($exist) {
            return self::createReturn(true, ['id' => $exist->id]);
        }
        // do fetch
        $res = $this->doFetchFile($bucket, $key, $url);
        if (!$res['status']) {
            return $res;
        }
        $file = [
            'bucket' => $this->getConfig('bucket'),
            'key' => $key,
            'file_name' => $file_name,
            'file_ext' => $file_ext,
            'file_url' => $this->getConfig('fetch')['domain'] . '/' . $key,
            'fetch_status' => QiniuFetchFileModel::FETCH_STATUS_DOING,
            'fetch_url' => $url,
            'uuid' => $uuid,
        ];
        $fileModel = new QiniuFetchFileModel();
        $res = $fileModel->data($file)->save();
        throw_if(!$res, new Exception('保存 QiniuFetchFileModel 失败'));
        return self::createReturn(true, ['id' => $fileModel->id]);
    }

    /**
     * 获取文件信息
     * @param $bucket
     * @param $key
     * @return array
     */
    function doStatFile($bucket, $key)
    {
        $auth = $this->getAuth();
        $bucketManager = new BucketManager($auth);
        list($ret, $err) = $bucketManager->stat($bucket, $key);
        if ($err) {
            // 资源不存在
            return self::createReturn(false, null, $err->message());
        }
        // $ret={"fsize":111,"hash":"xxx","mimeType":"application/octet-stream","putTime":13603956734587420,"md5":"xxxx"}
        return self::createReturn(true, $ret, '获取成功');
    }

    /**
     * 检测抓取状态
     * @param QiniuFetchFileModel $fileModel
     * @return array
     */
    function checkFetch(QiniuFetchFileModel $fileModel)
    {
        // 检测本身是否已同步
        if ($fileModel->fetch_status != QiniuFetchFileModel::FETCH_STATUS_DOING) {
            return self::createReturn(true, ['status' => $fileModel->fetch_status], '操作完成');
        }
        // do stat file
        $res = $this->doStatFile($fileModel->bucket, $fileModel->key);
        if ($res['status']) {
            // 有文件信息，说明已抓取成功
            $file_metadata = $res['data'];
            $fileModel->save([
                'fetch_status' => QiniuFetchFileModel::FETCH_STATUS_DONE,
                'file_type' => $file_metadata['mimeType'],
                'file_size' => $file_metadata['fsize'],
            ]);
            return self::createReturn(true, ['status' => QiniuFetchFileModel::FETCH_STATUS_DONE], '抓取成功');
        } else {
            // 未有文件信息，可能1、抓取失败,资源链接异常, 2、仍在抓取中,网路延迟
            return self::createReturn(true, ['status' => QiniuFetchFileModel::FETCH_STATUS_DOING], '抓取中');
        }
    }

    /**
     * 删除fetch文件记录和七牛云上资源
     * @param $file_uuid
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    function deleteFetchFile($file_uuid)
    {
        $fileModel = QiniuFetchFileModel::where('uuid', '=', $file_uuid)->find();
        if (!$fileModel) {
            return self::createReturn(false, null, '记录不存在');
        }
        $res = $this->doDeleteFile($fileModel->bucket, $fileModel->key);
        if (!$res['status']) {
            return $res;
        }
        $fileModel->delete();
        return self::createReturn(true, null, '操作完成');
    }

    /**
     * 设置fetch文件启用状态
     * @param $file_uuid
     * @param $is_block
     * @return array
     */
    function setFetchFileStatus($file_uuid, $status)
    {
        $fileModel = QiniuFetchFileModel::where('uuid', '=', $file_uuid)->find();
        if (!$fileModel) {
            return self::createReturn(false, null, '找不到文件');
        }
        $status = intval($status);
        if (!in_array($status, [0, 1])) {
            return self::createReturn(false, null, '参数异常：status');
        }
        $res = $this->doSetFileStatus($fileModel->bucket, $fileModel->key, $status);
        if (!$res['status']) {
            return $res;
        }
        $fileModel->save([
            'file_status' => $status
        ]);
        return self::createReturn(true, null, '操作完成');
    }
}