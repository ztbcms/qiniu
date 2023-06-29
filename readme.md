## 七牛组件

### 准备

```shell
composer require qiniu/php-sdk
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
```


### 接口

1. 获取上传Token`/qiniu/Upload/getUploadConfigV2`。不限制上传到七牛的内容格式，但可以通过`allow_suffix`来指定前端可用的文件格式。
返回格式：
```json
{
  "status": true,
  "code": 200,
  "data": {
    "key": "d\/202306\/$(etag)$(ext)",
    "upload_token": "=",
    "file_size_max_byte": 52428800,
    "file_size_max_mb": 50,
    "allow_suffix": "pdf,doc,docx,xls,xlsx,ppt,pptx"
  },
  "msg": "",
  "url": ""
}
```

2. 上传回调`qiniu/upload/callback`，返回内容：
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