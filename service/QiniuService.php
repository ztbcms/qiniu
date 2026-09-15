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
use Qiniu\Config as QiniuConfig;
use Qiniu\Http\Error as QiniuError;
use Qiniu\Storage\BucketManager;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\Exception;
use think\facade\Config;

/**
 * 七牛服务
 */
class QiniuService extends BaseService
{
    private $sence = '';
    /** @var QiniuConfig|null SDK 配置及区域缓存 */
    private $fetchSdkConfig;

    /**
     * 获取使用 HTTPS 的 SDK 配置。
     *
     * @return QiniuConfig
     */
    private function fetchConfig(): QiniuConfig
    {
        if ($this->fetchSdkConfig === null) {
            $this->fetchSdkConfig = new QiniuConfig();
            $this->fetchSdkConfig->useHTTPS = true;
        }
        return $this->fetchSdkConfig;
    }

    /**
     * 创建七牛 SDK 客户端。
     *
     * @return BucketManager
     */
    protected function fetchBucketManager(): BucketManager
    {
        return new BucketManager($this->getAuth(), $this->fetchConfig());
    }


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
        $this->fetchSdkConfig = null;
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
            // 已禁用、已启用则忽略判定
            if ($res['msg'] !== 'already enabled' && $res['msg'] !== 'already disabled') {
                return $res;
            }
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
        return $this->submitFetch($bucket, $key, $url, '');
    }

    /**
     * 检查当前空间中是否有可复用的抓取，不提交新任务。
     * 查询可能通过对象检查或确认宽限期更新已有记录的状态。
     *
     * @param string $key 对象键
     * @return array status=false 表示查询失败；成功时 data.id 为可复用记录 ID 或 null
     */
    public function findReusableFetch(string $key): array
    {
        $model = QiniuFetchFileModel::where(['bucket' => $this->getConfig('bucket'), 'key' => $key])->find();
        return $model ? $this->checkReusableFetch($model) : self::createReturn(true, ['id' => null]);
    }

    /**
     * 检查已有抓取，保留失败后对象晚到时的复用行为。
     *
     * @param QiniuFetchFileModel $model 抓取记录
     * @return array
     */
    private function checkReusableFetch(QiniuFetchFileModel $model): array
    {
        if ((int)$model->fetch_status === QiniuFetchFileModel::FETCH_STATUS_FAILD) {
            $stat = $this->doStatFile($model->bucket, $model->key);
            if ($stat['status']) {
                $model->save([
                    'fetch_status' => QiniuFetchFileModel::FETCH_STATUS_DONE,
                    'file_type' => $stat['data']['mimeType'],
                    'file_size' => $stat['data']['fsize'],
                    'fetch_error' => '',
                ]);
            } elseif (!in_array((int)($stat['data']['http_code'] ?? 0), self::STATUS_CODE_NO_EXIST, true)) {
                return $stat;
            }
        }
        $check = $this->checkFetch($model);
        if (!$check['status']) {
            return $check;
        }
        $model->refresh();
        $reusable = in_array((int)$model->fetch_status, [
            QiniuFetchFileModel::FETCH_STATUS_DONE,
            QiniuFetchFileModel::FETCH_STATUS_DOING,
        ], true);
        return self::createReturn(true, ['id' => $reusable ? $model->id : null]);
    }

    /**
     * 创建或恢复抓取；提交前再次检查已有任务，避免使用业务预检前的旧状态。
     * @param string $url 来源地址
     * @param string $key 对象键
     * @param string $file_name 展示名称
     * @param string $file_ext 后缀
     * @return array
     */
    public function createFetch($url, $key = '', $file_name = '', $file_ext = '')
    {
        $file_name = $file_name ?: StringUtils::getFileNameByURL($url);
        $file_ext = $file_ext ?: (StringUtils::getFileExtByURL($url) ?: StringUtils::getFileExtByFileName($file_name));
        if (!$file_ext) {
            return self::createReturn(false, null, '无法获取文件后缀');
        }
        $uuid = generateUniqueId();
        $key = $key ?: $this->getConfig('fetch')['prefix_key'] . date('Ym/dHis') . '-' . $uuid . '.' . $file_ext;
        $bucket = $this->getConfig('bucket');
        $model = QiniuFetchFileModel::where(['bucket' => $bucket, 'key' => $key])->find();
        if ($model) {
            $reusable = $this->checkReusableFetch($model);
            if (!$reusable['status'] || $reusable['data']['id'] !== null) {
                return $reusable;
            }
        }
        if (!$model) {
            $model = new QiniuFetchFileModel();
            $model->save([
                'bucket' => $bucket,
                'key' => $key,
                'uuid' => $uuid,
                'file_name' => $file_name,
                'file_ext' => $file_ext,
                'file_url' => $this->getConfig('fetch')['domain'] . '/' . $key,
                'fetch_url' => $url,
                'fetch_status' => QiniuFetchFileModel::FETCH_STATUS_DOING,
            ]);
        }
        $callback = trim((string)($this->getConfig('fetch')['callback_url'] ?? ''));
        if ($callback !== '') {
            if (
                parse_url($callback, PHP_URL_SCHEME) !== 'https' || !parse_url($callback, PHP_URL_HOST)
                || strpos($callback, '?') !== false || strpos($callback, '#') !== false
            ) {
                throw new \RuntimeException('七牛抓取 callback_url 必须为不带查询参数的公网 HTTPS 地址');
            }
        }
        // CAS 防止同一历史记录被两个重试同时提交；回调凭证按尝试轮换。
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $changed = QiniuFetchFileModel::where('id', $model->id)
            ->where('callback_hash', (string)$model->callback_hash)
            ->update([
                'callback_hash' => $hash,
                'fetch_task_id' => '',
                'fetch_error' => '',
                'fetch_status' => QiniuFetchFileModel::FETCH_STATUS_DOING,
                'submitted_at' => time(),
                'fetch_wait' => 0,
                'fetch_url' => $url,
                'update_time' => time()
            ]);
        if (!$changed) {
            return self::createReturn(true, ['id' => $model->id]);
        }
        if ($callback !== '') {
            $callback .= '?id=' . $model->id . '&token=' . $token;
        }
        $res = $this->submitFetch($bucket, $key, $url, $callback);
        $values = ['update_time' => time()];
        if ($res['status'] && !empty($res['data']['id'])) {
            $values += ['fetch_task_id' => $res['data']['id'], 'fetch_wait' => (int)($res['data']['wait'] ?? 0)];
        } else {
            // 网络超时可能已经被云端接受，不能当作明确失败立即重复提交。
            $values += ['fetch_error' => $res['msg'] ?? '七牛未返回抓取任务 ID'];
            if (!empty($res['data']['definitive'])) {
                $values['fetch_status'] = QiniuFetchFileModel::FETCH_STATUS_FAILD;
            }
        }
        QiniuFetchFileModel::where('id', $model->id)->where('callback_hash', $hash)
            ->where('fetch_status', QiniuFetchFileModel::FETCH_STATUS_DOING)->update($values);
        return $res['status'] && !empty($res['data']['id'])
            ? self::createReturn(true, ['id' => $model->id]) : self::createReturn(false, null, $values['fetch_error']);
    }

    /**
     * 提交网络请求，独立入口便于隔离测试。
     * @param string $bucket 空间
     * @param string $key 对象键
     * @param string $url 来源
     * @param string $callback 回调地址
     * @return array
     */
    protected function submitFetch(string $bucket, string $key, string $url, string $callback): array
    {
        [$data, $error] = $this->fetchBucketManager()->asynchFetch(
            $url, $bucket, null, $key, null, null,
            $callback !== '' ? $callback : null,
            $callback !== '' ? '{"bucket":"$(bucket)","key":"$(key)"}' : null,
            'application/json', null, 0, true
        );
        return $this->fetchSdkResult($data, $error);
    }

    /**
     * 获取对象元数据，保留 HTTP 状态区分不存在与存储错误。
     * @param string $bucket 空间
     * @param string $key 对象键
     * @return array
     */
    public function doStatFile($bucket, $key)
    {
        [$data, $error] = $this->fetchBucketManager()->stat($bucket, $key);
        return $this->fetchSdkResult($data, $error);
    }

    /**
     * 查询七牛排队信息；wait=-1 不是失败终态。
     * @param string $bucket 空间
     * @param string $id 七牛任务 ID
     * @return array
     */
    protected function queryFetchTask(string $bucket, string $id): array
    {
        $region = $this->fetchRegion($bucket);
        if (!$region['status']) {
            return $region;
        }
        [$data, $error] = $this->fetchBucketManager()->asynchFetchStatus($region['data']['zone'], rawurlencode($id));
        return $this->fetchSdkResult($data, $error);
    }

    /**
     * 通过 SDK 发现 Bucket 区域，供 asynchFetchStatus 的 zone 参数使用。
     *
     * @param string $bucket 空间名称
     * @return array
     */
    protected function fetchRegion(string $bucket): array
    {
        [$host, $error] = $this->fetchConfig()->getApiHostV2($this->getAuth()->getAccessKey(), $bucket);
        if ($error !== null) {
            return $this->fetchSdkResult(null, $error);
        }
        if (!preg_match('/^api-([a-z0-9-]+)\.qiniu(?:api)?\.com$/i', (string)parse_url($host, PHP_URL_HOST), $matches)) {
            return self::createReturn(false, null, '无法从七牛 SDK 的 API 域名确定抓取区域');
        }
        return self::createReturn(true, ['zone' => $matches[1]]);
    }

    /**
     * 转换 SDK 返回结构，保留业务状态机需要的错误码与明确失败标识。
     *
     * @param mixed $data SDK 响应数据
     * @param QiniuError|null $error SDK 错误
     * @return array
     */
    private function fetchSdkResult($data, ?QiniuError $error): array
    {
        if ($error !== null) {
            $code = (int)$error->code();
            $message = (string)$error->message();
            $message = preg_replace('~https?://[^\s]+~i', '[URL]', $message);
            return self::createReturn(false, [
                'http_code' => $code,
                'definitive' => $code >= 400 && $code < 500 && !in_array($code, [408, 429], true),
            ], mb_substr($message !== '' ? $message : '七牛 SDK 请求失败', 0, 1000));
        }
        if (!is_array($data)) {
            return self::createReturn(false, null, '七牛响应格式异常');
        }
        return self::createReturn(true, $data, '操作完成');
    }

    /**
     * 判断未取得任务 ID 的尝试是否超过提交确认宽限期。
     * @param QiniuFetchFileModel $model 抓取记录
     * @param int $now 当前 Unix 时间戳
     * @return bool
     */
    private function isSubmitConfirmationExpired(QiniuFetchFileModel $model, int $now): bool
    {
        if ((int)$model->fetch_status !== QiniuFetchFileModel::FETCH_STATUS_DOING
            || (string)$model->fetch_task_id !== '') {
            return false;
        }
        $timeout = (int)($this->getConfig('fetch')['submit_confirm_timeout'] ?? 30);
        $timeout = $timeout > 0 ? $timeout : 30;
        $submittedAt = (int)$model->submitted_at;
        return $submittedAt <= 0 || $now >= $submittedAt + $timeout;
    }

    /**
     * 获取抓取状态消息，避免失败终态返回“抓取中”。
     * @param QiniuFetchFileModel $model 抓取记录
     * @return string
     */
    private function fetchStatusMessage(QiniuFetchFileModel $model): string
    {
        if ((int)$model->fetch_status === QiniuFetchFileModel::FETCH_STATUS_DONE) {
            return '抓取成功';
        }
        return (string)$model->fetch_error ?: (
            (int)$model->fetch_status === QiniuFetchFileModel::FETCH_STATUS_FAILD ? '抓取失败' : '抓取中'
        );
    }

    /**
     * 检查对象与排队状态；失败回调或本地提交确认超时可结束尝试。
     * @param QiniuFetchFileModel $fileModel 抓取记录
     * @return array
     */
    public function checkFetch(QiniuFetchFileModel $fileModel)
    {
        if ((int)$fileModel->fetch_status !== QiniuFetchFileModel::FETCH_STATUS_DOING) {
            return self::createReturn(
                true,
                ['status' => (int)$fileModel->fetch_status, 'file' => $fileModel],
                $this->fetchStatusMessage($fileModel)
            );
        }
        $res = $this->doStatFile($fileModel->bucket, $fileModel->key);
        if ($res['status']) {
            QiniuFetchFileModel::where('id', $fileModel->id)
                ->where('callback_hash', (string)$fileModel->callback_hash)
                ->where('fetch_status', QiniuFetchFileModel::FETCH_STATUS_DOING)
                ->update([
                    'fetch_status' => QiniuFetchFileModel::FETCH_STATUS_DONE,
                    'file_type' => $res['data']['mimeType'],
                    'file_size' => $res['data']['fsize'],
                    'fetch_error' => '',
                    'update_time' => time()
                ]);
        } elseif (!in_array((int)($res['data']['http_code'] ?? 0), self::STATUS_CODE_NO_EXIST, true)) {
            return $res;
        } elseif ((string)$fileModel->fetch_task_id !== '') {
            $query = $this->queryFetchTask($fileModel->bucket, $fileModel->fetch_task_id);
            if (!$query['status']) {
                // 任务查询不存在也不能证明源文件删除；保留可恢复状态。
                return $query;
            }
            if (!isset($query['data']['wait']) || !is_numeric($query['data']['wait'])) {
                return self::createReturn(false, null, '七牛抓取状态响应不完整');
            }
            QiniuFetchFileModel::where('id', $fileModel->id)
                ->where('callback_hash', (string)$fileModel->callback_hash)
                ->update(['fetch_wait' => (int)$query['data']['wait']]);
        } elseif ($this->isSubmitConfirmationExpired($fileModel, time())) {
            // 只结束当前仍未取得 ID 的尝试，不覆盖并发提交结果或新的重试。
            QiniuFetchFileModel::where('id', $fileModel->id)
                ->where('callback_hash', (string)$fileModel->callback_hash)
                ->where('fetch_status', QiniuFetchFileModel::FETCH_STATUS_DOING)
                ->where('fetch_task_id', '')
                ->where('submitted_at', (int)$fileModel->submitted_at)
                ->update([
                    'fetch_status' => QiniuFetchFileModel::FETCH_STATUS_FAILD,
                    'fetch_error' => (int)$fileModel->submitted_at > 0
                        ? '七牛抓取提交结果未确认，等待超时，可重试'
                        : '抓取记录缺少提交时间和任务 ID，且对象不存在，请重试',
                    'update_time' => time(),
                ]);
        }
        $fileModel->refresh();
        return self::createReturn(
            true,
            ['status' => (int)$fileModel->fetch_status, 'file' => $fileModel],
            $this->fetchStatusMessage($fileModel)
        );
    }

    /**
     * 接收绑定单次尝试的回调；旧回调不能覆盖新尝试或成功结果。
     * @param int $id 记录 ID
     * @param string $token 256 位随机能力凭证（仅 HTTPS 传输）
     * @param array $payload 七牛成功或失败消息
     * @return bool 是否接受回调
     */
    public function acceptFetchCallback(int $id, string $token, array $payload): bool
    {
        $model = QiniuFetchFileModel::find($id);
        if (
            !$model || strlen($token) !== 64 || !hash_equals((string)$model->callback_hash, hash('sha256', $token))
            || ($payload['bucket'] ?? null) !== $model->bucket || ($payload['key'] ?? null) !== $model->key
        ) {
            return false;
        }
        if (isset($payload['err']) && (!is_string($payload['err']) || (isset($payload['code']) && !is_numeric($payload['code'])))) {
            return false;
        }
        if (!empty($payload['err'])) {
            $reason = '七牛源站抓取失败（' . (int)($payload['code'] ?? 0) . '）：' . (string)$payload['err'];
            // 不在错误信息中保存来源签名或回调凭证。
            $reason = preg_replace('~https?://[^\s]+~i', '[URL]', $reason);
            QiniuFetchFileModel::where('id', $id)->where('callback_hash', $model->callback_hash)
                ->where('fetch_status', QiniuFetchFileModel::FETCH_STATUS_DOING)
                ->update([
                    'fetch_status' => QiniuFetchFileModel::FETCH_STATUS_FAILD,
                    'fetch_error' => mb_substr($reason, 0, 1000),
                    'update_time' => time()
                ]);
        }
        // 成功仍须经 stat 确认，不能信任回调中的大小或 MIME。
        return true;
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
