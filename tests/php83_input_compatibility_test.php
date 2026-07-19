<?php

use app\qiniu\controller\Admin;
use app\qiniu\controller\Fetch;
use app\qiniu\controller\Upload;
use think\App;
use think\Request;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$app = new App();
$app->initialize();

$adminController = new Admin($app);
assertQiniuPagination($adminController, '2', '20', [2, 20], '数字字符串分页参数处理错误');
assertQiniuPagination($adminController, 0, 0, [1, 15], '零分页参数应使用默认值');
assertQiniuPagination($adminController, -1, -1, [1, 15], '负数分页参数应使用默认值');
assertQiniuPagination($adminController, 'invalid', [], [1, 15], '错误类型分页参数应使用默认值');
assertQiniuPagination($adminController, 1, 101, [1, 100], '每页记录数应限制为 100');

assertResponseMessage(
    callUploadConfig($app, []),
    '参数异常',
    '上传配置缺少 file_name 时应返回参数错误'
);
assertResponseMessage(
    callUploadConfig($app, ['file_name' => ['test.pdf']]),
    '参数异常',
    '上传配置 file_name 为数组时应返回参数错误'
);
$encodedFileNameResponse = callUploadConfig($app, ['file_name' => 'test%2Epdf']);
if (($encodedFileNameResponse['status'] ?? false) !== true
    || !str_ends_with((string)($encodedFileNameResponse['data']['key'] ?? ''), '.pdf')) {
    throw new RuntimeException('上传配置应正确解码合法文件名 response=' . var_export($encodedFileNameResponse, true));
}
assertResponseMessage(
    callUploadCallback($app, [
        'sence' => 'default',
        'key' => 'd/test.pdf',
        'fname' => 'test.pdf',
        'mimeType' => 'application/pdf',
        'fsize' => '100',
        'ext' => ['.pdf'],
    ]),
    '参数异常:ext',
    '上传回调 ext 为数组时应返回参数错误'
);
assertResponseMessage(
    callUploadCallback($app, [
        'sence' => 'default',
        'key' => 'd/test.pdf',
        'fname' => 'test.pdf',
        'mimeType' => 'application/pdf',
        'fsize' => '100',
        'ext' => '.pdf',
        'custom_file_name' => ['test.pdf'],
    ]),
    '参数异常:custom_file_name',
    '上传回调 custom_file_name 为数组时应返回参数错误'
);
$_SERVER['HTTP_AUTHORIZATION'] = 'invalid-test-signature';
assertResponseMessage(
    callUploadCallback($app, [
        'sence' => 'default',
        'key' => 'd/test.pdf',
        'fname' => 'test.pdf',
        'mimeType' => 'application/pdf',
        'fsize' => '100',
        'ext' => '.pdf',
    ]),
    '非法请求',
    '上传回调缺少可选 custom_file_name 时应继续完成签名校验'
);
// 无后缀文件：ext 缺失或空串应允许，继续到签名校验（与未修前业务语义一致）
assertResponseMessage(
    callUploadCallback($app, [
        'sence' => 'default',
        'key' => 'd/test-no-ext',
        'fname' => 'test-no-ext',
        'mimeType' => 'application/octet-stream',
        'fsize' => '100',
    ]),
    '非法请求',
    '上传回调缺少 ext 时应允许空扩展名并继续签名校验'
);
assertResponseMessage(
    callUploadCallback($app, [
        'sence' => 'default',
        'key' => 'd/test-no-ext',
        'fname' => 'test-no-ext',
        'mimeType' => 'application/octet-stream',
        'fsize' => '100',
        'ext' => '',
    ]),
    '非法请求',
    '上传回调 ext 为空串时应允许并继续签名校验'
);
unset($_SERVER['HTTP_AUTHORIZATION']);
assertResponseMessage(
    callCreateFetch($app, []),
    '参数异常:url',
    '拉取接口缺少 url 时应返回参数错误'
);
assertResponseMessage(
    callCreateFetch($app, ['url' => ['https://example.com/test.pdf']]),
    '参数异常:url',
    '拉取接口 url 为数组时应返回参数错误'
);

echo "qiniu PHP 8.3 input compatibility tests passed\n";

/**
 * 断言七牛分页参数归一化结果
 *
 * @param Admin $controller 七牛后台控制器
 * @param mixed $page 原始页码
 * @param mixed $limit 原始每页记录数
 * @param array $expected 预期分页参数
 * @param string $message 错误信息
 * @return void
 */
function assertQiniuPagination(Admin $controller, $page, $limit, array $expected, string $message): void
{
    $method = new ReflectionMethod(Admin::class, 'normalizePagination');
    $method->setAccessible(true);
    $actual = $method->invoke($controller, $page, $limit);
    if ($actual !== $expected) {
        throw new RuntimeException($message . ' actual=' . var_export($actual, true));
    }
}

/**
 * 调用上传配置接口
 *
 * @param App $app 应用对象
 * @param array $params 请求参数
 * @return array
 */
function callUploadConfig(App $app, array $params): array
{
    $request = (new Request())
        ->withServer([
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'localhost',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => '80',
        ])
        ->withGet($params);
    $app->instance('request', $request);

    return (new Upload($app))->getUploadConfig($request)->getData(true);
}

/**
 * 调用上传回调接口
 *
 * @param App $app 应用对象
 * @param array $params 请求参数
 * @return array
 */
function callUploadCallback(App $app, array $params): array
{
    $request = (new Request())
        ->withServer([
            'REQUEST_METHOD' => 'POST',
            'HTTP_HOST' => 'localhost',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => '80',
        ])
        ->withPost($params);
    $app->instance('request', $request);

    return (new Upload($app))->callback()->getData(true);
}

/**
 * 调用拉取接口
 *
 * @param App $app 应用对象
 * @param array $params 请求参数
 * @return array
 */
function callCreateFetch(App $app, array $params): array
{
    $request = (new Request())
        ->withServer(['REQUEST_METHOD' => 'POST'])
        ->withPost($params);
    $app->instance('request', $request);

    return (new Fetch($app))->createFetch()->getData(true);
}

/**
 * 断言接口返回指定消息
 *
 * @param array $response 接口响应
 * @param string $expectedMessage 预期消息
 * @param string $message 断言失败消息
 * @return void
 */
function assertResponseMessage(array $response, string $expectedMessage, string $message): void
{
    if (($response['status'] ?? true) !== false || ($response['msg'] ?? '') !== $expectedMessage) {
        throw new RuntimeException($message . ' response=' . var_export($response, true));
    }
}
