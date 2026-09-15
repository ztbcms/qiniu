## 七牛组件

### 准备

```shell
composer require "qiniu/php-sdk:^7.14"
```

### 配置

`.env`添加配置：
```ini
[qiniu]
access_key=xxxx
secret_key=xxx
bucket=test
domain=https://xxx.net
upload_allow_suffix=pdf,doc,docx,xls,xlsx,ppt,pptx
upload_prefix_key=d/
upload_size_limit=10485760
fetch_prefix_key=fetch/
# 可选：可以为空；启用七牛异步抓取失败回调时填写公网 HTTPS 地址；服务会自动追加 id/token
fetch_callback_url="https://api.example.com/qiniu/fetch/callback"
# 未取得七牛任务 ID 时的提交确认宽限期（秒）
fetch_submit_confirm_timeout=30
```


### 上传接口

#### 获取上传 Token

`/qiniu/Upload/getUploadConfigV2` 不限制上传到七牛的内容格式，但可以通过 `allow_suffix` 指定前端可用的文件格式。
返回格式：
```json
{
  "status": true,
  "code": 200,
  "data": {
    "key": "d/202306/29172251-$(etag)$(ext)",
    "upload_token": "xxxx",
    "file_size_max_byte": 11534336,
    "file_size_max_mb": 11,
    "allow_suffix": "pdf,doc,docx,xls,xlsx,ppt,pptx",
    "upload_url": "https://up-z2.qiniup.com"
  },
  "msg": "",
  "url": ""
}
```

#### 上传回调

`qiniu/upload/callback` 返回内容：
```json
{
    "code": 200,
    "data": {
        "bucket": "xiaofujian",
        "create_time": 1688007021,
        "file_ext": "xlsx",
        "file_name": "xxxx 1.xlsx",
        "file_size": 10922,
        "file_type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        "file_url": "https://xxx.net/dxxxx.xlsx",
        "key": "d/xxxxx.xlsx",
        "uuid": "73e1fab7c3bac42c97d985b06910bbf7"
    },
    "msg": "",
    "status": true,
    "url": ""
}
```

### Fetch 远程文件抓取

Fetch 用于让七牛云直接从公网 URL 拉取文件并保存到 Bucket，服务端无需中转下载。抓取由七牛异步执行，业务端采用“提交任务 -> 轮询查询结果”的模式。

#### HTTP 接口

##### 1. 创建抓取：`POST /qiniu/Fetch/createFetch`

- **请求参数**：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| `url` | string | 是 | 七牛可以访问的 HTTP/HTTPS 文件地址。建议 URL 编码后传递。 |

- **示例**：
```bash
curl -X POST 'https://api.example.com/qiniu/Fetch/createFetch' \
  --data-urlencode 'url=https://static.example.com/files/example.docx'
```

- **响应示例**（返回本地抓取记录的查询凭证 `token`）：
```json
{
  "status": true,
  "code": 200,
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJI..."
  },
  "msg": "",
  "url": ""
}
```

##### 2. 查询抓取：`POST /qiniu/Fetch/queryFetch`

- **请求参数**：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| `token` | string | 是 | 创建抓取接口返回的 JWT 查询凭证。 |

- **示例**：
```bash
curl -X POST 'https://api.example.com/qiniu/Fetch/queryFetch' \
  --data-urlencode 'token=eyJ0eXAiOiJKV1QiLCJhbGciOiJI...'
```

- **响应示例**：
```json
{
  "status": true,
  "code": 200,
  "data": {
    "status": 1,
    "file": {
      "id": 123,
      "bucket": "example-bucket",
      "key": "fetch/202609/example.docx",
      "file_url": "https://cdn.example.com/fetch/202609/example.docx",
      "file_size": 1048576,
      "file_type": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
      "fetch_status": 1,
      "fetch_error": ""
    }
  },
  "msg": ""
}
```

- **状态码说明（`data.status` / `QiniuFetchFileModel`）**：

| 状态值 | 常量 | 含义说明 |
| --- | --- | --- |
| `0` | `FETCH_STATUS_DOING` | 抓取中或结果尚未确认 |
| `1` | `FETCH_STATUS_DONE` | 抓取完成，对象已在七牛存储桶落盘并确认 |
| `2` | `FETCH_STATUS_FAILD` | 明确失败，失败原因见 `msg` 或 `file.fetch_error` |

---

#### PHP 服务调用

在服务端业务逻辑中，推荐直接使用 `QiniuService`：

```php
use app\qiniu\service\QiniuService;
use app\qiniu\model\QiniuFetchFileModel;

$service = new QiniuService();

// 1. 创建抓取任务（按 bucket + key 自动复用进行中/已完成的记录）
$created = $service->createFetch(
    'https://static.example.com/files/example.docx',
    'fetch/202609/example.docx',
    'example.docx',
    'docx'
);

if (!$created['status']) {
    throw new \RuntimeException($created['msg']);
}

// 2. 查询抓取任务进度
$record = QiniuFetchFileModel::find($created['data']['id']);
$result = $service->checkFetch($record); // 返回 status: 0(抓取中) / 1(完成) / 2(失败)
```

> **可选调用**：若需在提交前预先检查是否有可复用记录，可调用 `$service->findReusableFetch($key)`。若 `data.id` 不为 `null` 则可直接复用，避免重复提交。

---

#### 配置说明 (`fetch_callback_url`)

- **可选配置**：若配置了公网 HTTPS 完整地址（例如 `https://api.example.com/qiniu/fetch/callback`），服务端会在提交时向七牛注册回调。抓取失败时七牛会主动通知服务端，加速感知失败状态。
- **可以为空**：未配置回调时，功能完全正常。服务端查询时会自动通过七牛 `stat` 接口确认文件落盘状态；若抓取失败，会在提交宽限期（`fetch_submit_confirm_timeout`，默认 30 秒）到期后允许重试。

---

七牛官方异步抓取接口说明：[异步第三方资源抓取](https://developer.qiniu.com/kodo/api/4097/asynch-fetch)。

