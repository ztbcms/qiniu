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