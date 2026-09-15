<?php
/**
 * Author: Jayin Taung <tonjayin@gmail.com>
 */

namespace app\qiniu\controller;

use app\api\controller\BaseApi;
use app\common\service\jwt\JwtService;
use app\qiniu\model\QiniuFetchFileModel;
use app\qiniu\service\QiniuService;

/**
 * 拉取文件
 */
class Fetch extends BaseApi
{
    /** 七牛回调请求体允许的最大大小（16 KiB）。 */
    private const MAX_CALLBACK_BODY_BYTES = 16 * 1024;

    /** 在限制值基础上多读取一个字节，用于识别超限请求。 */
    private const CALLBACK_BODY_READ_BYTES = self::MAX_CALLBACK_BODY_BYTES + 1;

    /** @var array 回调由单次随机能力凭证认证，不使用登录令牌。 */
    protected $skipAuthActions = ['callback'];

    /**
     * 七牛异步抓取回调；限制请求体，拒绝伪造或旧尝试的回调。
     * @return \think\response\Json
     */
    public function callback()
    {
        if (!request()->isPost()) {
            return json(['error' => 'method not allowed'], 405);
        }
        $id = request()->get('id');
        $token = request()->get('token');
        // 多读一个字节，区分“恰好达到上限”和“超过上限”，同时避免无界读取请求体。
        $body = file_get_contents('php://input', false, null, 0, self::CALLBACK_BODY_READ_BYTES);
        if (!is_scalar($id) || !is_string($token) || strlen($body) > self::MAX_CALLBACK_BODY_BYTES) {
            return json(['error' => 'invalid callback'], 400);
        }
        $payload = json_decode($body, true);
        if (!is_array($payload) || !(new QiniuService())->acceptFetchCallback((int)$id, $token, $payload)) {
            return json(['error' => 'invalid callback'], 403);
        }
        return json(['success' => true]);
    }

    /**
     * 创建拉取
     * @return \think\response\Json
     * @throws \Throwable
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    function createFetch()
    {
        $url = input('url', '');
        if (!is_string($url)) {
            return self::makeJsonReturn(false, null, '参数异常:url');
        }
        $url = urldecode($url);
        if (empty($url)) {
            return self::makeJsonReturn(false, null, '参数异常:url');
        }
        $service = new QiniuService();
        $res = $service->createFetch($url);
        if (!$res['status']) {
            return json($res);
        }
        return self::makeJsonReturn(true, ['token' => (new JwtService())->createToken(['id' => $res['data']['id']])]);
    }

    /**
     * 查询拉取进度
     * @return \think\response\Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    function queryFetch()
    {
        $token = input('token');
        $data = (new JwtService())->parserToken($token)['data'];
        $id = $data['id'];
        $fileModel = QiniuFetchFileModel::where('id', $id)->find();
        if (!$fileModel) {
            return self::makeJsonReturn(false, null, '找不到记录');
        }
        $qiniuService = new QiniuService();
        $res = $qiniuService->checkFetch($fileModel);
        return json($res);
    }

}
