<?php
/**
 * Author: Jayin Taung <tonjayin@gmail.com>
 */

namespace app\qiniu\controller;

use app\api\controller\BaseApi;
use app\common\service\jwt\JwtService;
use app\qiniu\model\QiniuFetchFileModel;
use app\qiniu\service\QiniuService;

class Fetch extends BaseApi
{
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
        $url = urldecode(input('url'));
        $service = new QiniuService();
        $res = $service->createFetch($url);
        if (!$res) {
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