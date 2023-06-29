<?php
/**
 * Author: Jayin Taung <tonjayin@gmail.com>
 */

namespace app\qiniu\controller;

use app\api\controller\BaseApi;
use app\qiniu\model\QiniuUploadFileModel;
use app\qiniu\service\QiniuService;
use Qiniu\Auth;
use think\Request;

class Upload extends BaseApi
{
    protected $skillAuthActions = ['getUploadConfig', 'callback', 'getUploadConfigV2', 'callbackV2'];

    /**
     * 上传配置
     * @deprecated 建议采用 getUploadConfigV2()
     * @return \think\response\Json
     */
    function getUploadConfig(Request $request)
    {
        $file_name = urldecode(input('file_name'));
        if (empty($file_name)) {
            return self::makeJsonReturn(false, null, '参数异常');
        }
        $_arr = explode('.', $file_name);
        $file_ext = $_arr[count($_arr) - 1];
        $sence = input('sence', 'default');
        $qiniuService = new QiniuService($sence);
        $config = $qiniuService->config();
        $key = $config['upload']['prefix_key'] . date('Ym') . '/' . strtoupper(generateUniqueId()) . '.' . $file_ext;
        $expires = 360;// 有效时间，单位：秒
        $policy = [
            'forceSaveKey' => true,
            'saveKey' => $key,
            'fsizeLimit' => $config['upload']['size_limit'],
            'callbackUrl' => api_url('qiniu/upload/callback'),
            'callbackBody' => "sence={$sence}&key=$(key)&fname=$(fname)&fsize=$(fsize)&mimeType=$(mimeType)&etag=$(etag)&ext=$(ext)"
        ];
        $upload_token = $qiniuService->getUploadToken($key, $expires, $policy);
        $ret = [
            'key' => $key,
            'upload_token' => $upload_token,
            'file_size_max_byte' => $config['upload']['size_limit'],
            'file_size_max_mb' => intval($config['upload']['size_limit'] / 1024 / 1024),
            'allow_suffix' => $config['upload']['allow_suffix'],
        ];
        return self::makeJsonReturn(true, $ret);
    }

    /**
     * 上传完成回调
     * @see https://developer.qiniu.com/kodo/1206/put-policy
     * @see https://developer.qiniu.com/kodo/1654/response-body
     */
    function callback()
    {
        $sence = input('sence');
        if (empty($sence)) {
            return self::makeJsonReturn(false, null, '参数异常:sence');
        }
        $key = input('key');
        $fname = input('fname');
        $mimeType = input('mimeType');
        $fsize = input('fsize');
        $file_ext = ltrim(input('ext'), '.');
        if (empty($key) || empty($fname) || empty($mimeType) || empty($fsize)) {
            return self::makeJsonReturn(false, null, '参数异常');
        }
        $qiniuService = new QiniuService($sence);
        $config = $qiniuService->config();
        $accessKey = $config['access_key'];
        $secretKey = $config['secret_key'];;
        $bucket = $config['bucket'];;
        $auth = new Auth($accessKey, $secretKey);
        //获取回调的body信息
        $callbackBody = file_get_contents('php://input');
        //回调的contentType
        $contentType = 'application/x-www-form-urlencoded';
        //回调的签名信息，可以验证该回调是否来自存储服务
        $authorization = $_SERVER['HTTP_AUTHORIZATION'];
        //存储服务回调的url，具体可以参考：http://developer.qiniu.com/docs/v6/api/reference/security/put-policy.html
        $url = api_url('/qiniu/upload/callback');
        $isQiniuCallback = $auth->verifyCallback($contentType, $authorization, $url, $callbackBody);
        if ($isQiniuCallback) {
            $upload_file = [
                'bucket' => $config['bucket'],
                'key' => $key,
                'file_name' => $fname,
                'file_type' => $mimeType,
                'file_ext' => $file_ext,
                'file_size' => intval($fsize),
                'file_url' => $config['upload']['domain'] . '/' . $key,
                'create_time' => time(),
            ];
            // 记录上传信息
            $exist = QiniuUploadFileModel::where([
                'bucket' => $bucket,
                'key' => $upload_file['key']
            ])->find();
            if ($exist) {
                $upload_file['uuid'] = $exist['uuid'];
                return self::makeJsonReturn(true, $upload_file);
            }
            $upload_file['uuid'] = generateUniqueId();
            $model = new QiniuUploadFileModel();
            $model->data($upload_file)->save();
            return self::makeJsonReturn(true, $upload_file);
        } else {
            return self::makeJsonReturn(false, null, '非法请求');
        }
    }

    /**
     * 上传配置V2
     * @return \think\response\Json
     */
    function getUploadConfigV2(Request $request)
    {
        $sence = input('sence', 'default');
        $qiniuService = new QiniuService($sence);
        $config = $qiniuService->config();
        $key = $config['upload']['prefix_key'] . date('Ym') . '/'.date('dHis').'-$(etag)$(ext)';
        $expires = 360;// 有效时间，单位：秒
        $policy = [
            'forceSaveKey' => true,
            'saveKey' => $key,
            'fsizeLimit' => $config['upload']['size_limit'],
            'callbackUrl' => api_url('qiniu/upload/callback'),
            'callbackBody' => "sence={$sence}&key=$(key)&fname=$(fname)&fsize=$(fsize)&mimeType=$(mimeType)&etag=$(etag)&ext=$(ext)"
        ];
        $upload_token = $qiniuService->getUploadToken($key, $expires, $policy);
        $ret = [
            'key' => $key,
            'upload_token' => $upload_token,
            'file_size_max_byte' => $config['upload']['size_limit'],
            'file_size_max_mb' => intval($config['upload']['size_limit'] / 1024 / 1024),
            'allow_suffix' => $config['upload']['allow_suffix'],
        ];
        return self::makeJsonReturn(true, $ret);
    }
}