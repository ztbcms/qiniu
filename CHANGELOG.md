# 更新日志

## 1.1.0 — 2026-09-14

### 变更

- **新增复用查询**：新增 `findReusableFetch($key)`，支持显式检查可复用记录；`createFetch()` 移除业务预检回调，统一由上层编排。
- **任务状态与排队持久化**：新增记录七牛异步抓取任务 ID、排队数、提交时间、失败原因与单次回调凭证摘要。
- **状态确认机制**：`checkFetch()` 优先以七牛 `stat` 对象落盘作为成功标准；七牛 `wait=-1` 仅代表已处理，不作为失败终态。
- **异步回调通知**：新增 `POST /qiniu/fetch/callback` 支持七牛失败回调与单次凭证校验，失败原因自动脱敏。
- **SDK 调用规范化**：移除自定义传输层，直接使用七牛官方 SDK 的 `asynchFetch()`、`asynchFetchStatus()` 和 `stat()`。
- **数据库迁移增强**：`migrates/upgrade_1_1_0.php` 支持无参执行升级 `up`，以及通过 `--dry-run` 预览与 `--force` 显式回滚 `down`。
- **Bug 修复**：修复创建抓取 API 未正确返回提交失败的问题。

### 升级与迁移

1. **执行数据库迁移**（在 `tp6/` 目录运行，幂等可重跑）：
   ```sh
   php app/qiniu/migrates/upgrade_1_1_0.php
   ```
   > 如需回滚表结构，可执行：
   > ```sh
   > php app/qiniu/migrates/upgrade_1_1_0.php down --dry-run --to=1.0.5
   > php app/qiniu/migrates/upgrade_1_1_0.php down --force --to=1.0.5
   > ```

2. **清除缓存并重启**：若启用了表结构缓存，需清除 `qiniu_fetch_file` 的 schema 缓存并重启 PHP / Worker。

3. **配置失败回调（可选）**：如需加速感知失败，在 `.env` 中添加公网 HTTPS 地址：
   ```ini
   [qiniu]
   fetch_callback_url="https://你的业务API域名/qiniu/fetch/callback"
   fetch_submit_confirm_timeout = 30
   ```

### 兼容说明

- 保留常量 `FETCH_STATUS_FAILD` 的历史拼写，兼容旧调用。
- 回滚命令 `down --force` 会删除 1.1.0 新增字段并丢失字段中的数据，执行前须备份数据库并停止 Worker。

### 测试验证

在 `tp6/` 目录下运行测试：

```sh
php -d error_reporting=32767 app/qiniu/tests/fetch_sdk_test.php
php -d error_reporting=32767 app/qiniu/tests/fetch_lifecycle_test.php
php -d error_reporting=32767 app/qiniu/tests/php83_input_compatibility_test.php
php -d error_reporting=32767 app/qiniu/tests/migration_110_test.php
# 真实七牛联调测试（可选）
QINIU_LIVE_TEST=1 php -d error_reporting=32767 app/qiniu/tests/fetch_live_test.php
```

