<?php

/** SDK 调用边界测试，不访问网络或业务数据库。 */
require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** 记录 SDK 调用并返回可控响应。 */
class RecordingFetchSdk
{
    /** @var array 最近调用的参数 */
    public $arguments = [];
    /** @var array SDK 响应 */
    public $response = [['id' => 'task-id', 'wait' => -1], null];

    /** 初始化测试替身，不创建真实凭证。 */
    public function __construct() {}

    /**
     * 记录异步提交参数，签名与 SDK 一致。
     *
     * @return array
     */
    public function asynchFetch($url, $bucket, $host = null, $key = null, $md5 = null, $etag = null,
        $callbackurl = null, $callbackbody = null, $callbackbodytype = 'application/x-www-form-urlencoded',
        $callbackhost = null, $file_type = 0, $ignore_same_key = false)
    {
        $this->arguments = func_get_args();
        return $this->response;
    }

    /**
     * @param string $zone 区域
     * @param string $id 任务 ID
     * @return array
     */
    public function asynchFetchStatus($zone, $id)
    {
        $this->arguments = func_get_args();
        return $this->response;
    }

    /**
     * @param string $bucket 空间
     * @param string $key 对象键
     * @return array
     */
    public function stat($bucket, $key)
    {
        $this->arguments = func_get_args();
        return $this->response;
    }
}

// SDK 客户端是 final 类，在本独立测试进程的自动加载边界替换它。
class_alias(RecordingFetchSdk::class, \Qiniu\Storage\BucketManager::class);

/** 注入可控 SDK，执行真实业务方法。 */
class SdkFetchServiceTest extends \app\qiniu\service\QiniuService
{
    /** @var RecordingFetchSdk SDK 替身 */
    public $sdk;

    /** @return \Qiniu\Storage\BucketManager */
    protected function fetchBucketManager(): \Qiniu\Storage\BucketManager { return $this->sdk; }

    /** @param string $bucket 空间 @return array */
    protected function fetchRegion(string $bucket): array { return ['status' => true, 'data' => ['zone' => 'z2']]; }
}

/**
 * @param bool $condition 断言条件
 * @param string $message 描述
 * @return void
 */
function sdkAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new \RuntimeException($message); }
    echo 'PASS ' . $message . PHP_EOL;
}

$service = new SdkFetchServiceTest();
$sdk = $service->sdk = new RecordingFetchSdk();
$submit = new \ReflectionMethod($service, 'submitFetch');
$query = new \ReflectionMethod($service, 'queryFetchTask');
$result = $submit->invoke($service, 'bucket', 'key.doc', 'https://source.example/a.doc', 'https://api.example/callback?token=secret');
sdkAssert($result['status'] && $result['data']['id'] === 'task-id', 'SDK submit result preserved');
sdkAssert($sdk->arguments[0] === 'https://source.example/a.doc' && $sdk->arguments[1] === 'bucket'
    && $sdk->arguments[3] === 'key.doc', 'source, bucket and key passed to SDK');
sdkAssert($sdk->arguments[6] === 'https://api.example/callback?token=secret'
    && $sdk->arguments[7] === '{"bucket":"$(bucket)","key":"$(key)"}'
    && $sdk->arguments[8] === 'application/json' && $sdk->arguments[11] === true, 'callback and ignore_same_key preserved');
$service->doFetchFile('bucket', 'key.doc', 'https://source.example/a.doc');
sdkAssert($sdk->arguments[6] === null && $sdk->arguments[7] === null, 'optional callback omitted');
$query->invoke($service, 'bucket', 'abc+/=');
sdkAssert($sdk->arguments === ['z2', 'abc%2B%2F%3D'], 'SDK query region and opaque ID encoding');
$sdk->response = [['fsize' => 44544, 'mimeType' => 'application/msword'], null];
$result = $service->doStatFile('bucket', 'key.doc');
sdkAssert($result['status'] && $result['data']['fsize'] === 44544 && $sdk->arguments === ['bucket', 'key.doc'], 'SDK stat metadata preserved');
foreach ([612, 401, 408, 429, 500, 0] as $code) {
    $error = new \Qiniu\Http\Error('https://example.com', new \Qiniu\Http\Response($code, 0, [], null, 'error https://example.com/?token=secret'));
    $sdk->response = [null, $error];
    $result = $service->doStatFile('bucket', 'key.doc');
    sdkAssert(!$result['status'] && $result['data']['http_code'] === $code
        && $result['data']['definitive'] === ($code === 401) && strpos($result['msg'], 'secret') === false,
        'SDK error classification and redaction: ' . $code);
}
echo "Qiniu SDK tests passed\n";
