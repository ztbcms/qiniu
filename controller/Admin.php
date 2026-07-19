<?php
/**
 * Author: Jayin Taung <tonjayin@gmail.com>
 */

namespace app\qiniu\controller;

use app\common\controller\AdminController;
use app\qiniu\model\QiniuFetchFileModel;
use app\qiniu\model\QiniuUploadFileModel;
use app\qiniu\service\QiniuService;

class Admin extends AdminController
{
    /** 单页最大记录数 */
    const MAX_LIMIT = 100;

    /**
     * 上传文件列表
     */
    function files()
    {
        $action = input('_action');
        if ($action == 'getList') {
            list($page, $limit) = $this->normalizePagination(input('page', 1), input('limit', 15));
            $datetime = input('datetime', '');
            $file_name = input('file_name', '');
            $file_status = input('file_status', '');
            $sort_field = input('sort_field', '');
            $sort_order = input('sort_order', '');
            $where = [];
            if ($datetime) {
                $_start_time = strtotime($datetime[0]);
                $_end_time = strtotime($datetime[1] . '23:59:59');
                $where[] = ['create_time', 'between', [$_start_time, $_end_time]];
            }
            if ($file_name) {
                $where [] = ['file_name', 'like', '%' . $file_name . '%'];
            }
            if ($file_status !== 'all') {
                $where [] = ['file_status', '=', $file_status];
            }
            $order = 'id desc';
            if ($sort_field) {
                $order = $sort_field . ' ' . $sort_order . ' ' . $order;
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

    /**
     * 抓取文件管理
     */
    function fetch_files()
    {
        $action = input('_action');
        if ($action == 'getList') {
            list($page, $limit) = $this->normalizePagination(input('page', 1), input('limit', 15));
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
            $lists = QiniuFetchFileModel::where($where)->order($order)->page($page)->limit($limit)->select()->toArray();
            $total = QiniuFetchFileModel::where($where)->count();
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
            $res = $qiniuService->deleteFetchFile($uuid);
            return json($res);
        }
        // 文件状态
        if ($action == 'changeFileStatus') {
            $uuid = input('uuid');
            $file_status = input('file_status');
            $qiniuService = new QiniuService();
            $res = $qiniuService->setFetchFileStatus($uuid, $file_status);
            return json($res);
        }
        // 检测抓取状态
        if ($action == 'queryFetchStatus') {
            $uuid = input('uuid');
            $fileModel = QiniuFetchFileModel::where('uuid', $uuid)->find();
            $qiniuService = new QiniuService();
            $res = $qiniuService->checkFetch($fileModel);
            return json($res);
        }
        return view('fetch_files');
    }

    /**
     * 归一化分页参数
     *
     * 页码和每页记录数仅接受整数或去除首尾空格后的整数格式字符串
     * 0、负数、非数字字符串、数组及其他类型分别回退为 1 和 15
     * 每页记录数超过 100 时限制为 100
     *
     * @param mixed $page 原始页码
     * @param mixed $limit 原始每页记录数
     * @return array
     */
    private function normalizePagination($page, $limit)
    {
        $page = $this->normalizePositiveInteger($page, 1);
        $limit = min($this->normalizePositiveInteger($limit, 15), self::MAX_LIMIT);

        return [$page, $limit];
    }

    /**
     * 归一化正整数
     *
     * 接受整数或去除首尾空格后的整数格式字符串且结果必须大于 0
     * 浮点数、布尔值、null、数组、对象及非整数格式字符串均返回默认值
     *
     * @param mixed $value 原始值
     * @param int $default 默认值
     * @return int
     */
    private function normalizePositiveInteger($value, $default)
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/^[+-]?\d+$/D', trim($value)) === 1) {
            $number = (int) trim($value);
        } else {
            return $default;
        }

        return $number > 0 ? $number : $default;
    }
}
