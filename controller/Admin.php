<?php
/**
 * Author: Jayin Taung <tonjayin@gmail.com>
 */

namespace app\qiniu\controller;

use app\common\controller\AdminController;
use app\qiniu\model\QiniuUploadFileModel;
use app\qiniu\service\QiniuService;

class Admin extends AdminController
{
    /**
     * 文件列表
     */
    function files()
    {
        $action = input('_action');
        if ($action == 'getList') {
            $page = input('page', 1);
            $limit = input('limit', 15);
            $datetime = input('datetime', '');
            $file_name = input('file_name', '');
            $sort_field = input('sort_field', '');
            $sort_order = input('sort_order', '');
            $where = [];
            if ($datetime) {
                $_start_time = strtotime($datetime[0]);
                $_end_time = strtotime($datetime[1] . '23:59:59');
                $where[] = ['create_time', 'between', [$_start_time, $_end_time]];
            }
            $order = 'id desc';
            if ($sort_field) {
                $order = $sort_field . ' ' . $sort_order . ' ' . $order;
            }
            if ($file_name) {
                $where [] = ['file_name', 'like', '%' . $file_name . '%'];
            }
            $lists = QiniuUploadFileModel::where($where)->order($order)->page($page)->limit($limit)->select()->toArray();
            $total = QiniuUploadFileModel::where($where)->count();
            $ret = [
                'items' => $lists,
                'page' => intval($page),
                'limit' => intval($limit),
                'total_items' => intval($total),
                'total_pages' => intval(ceil($total / $limit)),
            ];
            return json(self::createReturn(true, $ret));
        }
        // 删除文件
        if ($action == 'deleteFile') {
            $uuid = input('uuid');
            $qiniuService = new QiniuService();
            $res = $qiniuService->deleteFile($uuid);
            return json($res);
        }
        // 文件状态
        if ($action == 'changeFileStatus') {
            $uuid = input('uuid');
            $file_status = input('file_status');
            $qiniuService = new QiniuService();
            $res = $qiniuService->setFileStatus($uuid, $file_status);
            return json($res);
        }
        return view('files');
    }
}