<?php

/**
 * 七牛 1.1.0 数据库迁移。
 *
 * 默认执行升级，兼容旧的无参数调用方式：
 *   php app/qiniu/migrates/upgrade_1_1_0.php
 *   php app/qiniu/migrates/upgrade_1_1_0.php up
 *
 * 回滚必须先预览，再显式确认：
 *   php app/qiniu/migrates/upgrade_1_1_0.php down --dry-run
 *   php app/qiniu/migrates/upgrade_1_1_0.php down --force
 *   php app/qiniu/migrates/upgrade_1_1_0.php down --force --to=1.0.5
 *
 * 回滚只删除 1.1.0 新增的数据库字段并更新模块登记版本，不删除抓取记录、七牛对象或队列消息。
 * MySQL DDL 会隐式提交，执行前必须备份数据库并暂停 qiniu 相关 Worker。
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$app = new \think\App(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR);
$app->initialize();

/**
 * 返回 1.1.0 新增字段定义。
 *
 * @return array<string, string> 字段名到 SQL 定义的映射
 */
function qiniu110ColumnDefinitions(): array
{
    return [
        'fetch_task_id' => "varchar(512) NOT NULL DEFAULT '' COMMENT '七牛异步任务ID，历史记录为空'",
        'fetch_wait' => "int NOT NULL DEFAULT '0' COMMENT '七牛异步抓取排队数：大于0为前方排队任务数，0为当前任务正在处理，-1为任务至少已处理过一次且可能进入重试，不代表成功或失败'",
        'fetch_error' => "varchar(1000) NOT NULL DEFAULT '' COMMENT '脱敏抓取失败或请求异常原因'",
        'submitted_at' => "int NOT NULL DEFAULT '0' COMMENT '最近提交时间戳'",
        'callback_hash' => "char(64) NOT NULL DEFAULT '' COMMENT '当前尝试回调凭证SHA256'",
    ];
}

/**
 * 返回本次迁移使用的数据库上下文。
 *
 * @return array{db: mixed, prefix: string, table: string} 数据库对象、表前缀和完整表名
 */
function qiniu110MigrationContext(): array
{
    $db = \think\facade\Db::connect();
    $prefix = (string)$db->getConfig('prefix');
    if (!preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) {
        throw new \RuntimeException('数据库表前缀不合法');
    }
    return [
        'db' => $db,
        'prefix' => $prefix,
        'table' => $prefix . 'qiniu_fetch_file',
    ];
}

/**
 * 检查表是否存在。
 *
 * @param mixed $db ThinkPHP 数据库连接
 * @param string $table 完整表名
 * @return bool
 */
function qiniu110TableExists($db, string $table): bool
{
    foreach ($db->query('SHOW TABLES') as $row) {
        if (in_array($table, array_values($row), true)) {
            return true;
        }
    }
    return false;
}

/**
 * 获取抓取表已有字段。
 *
 * @param mixed $db ThinkPHP 数据库连接
 * @param string $table 完整表名
 * @return array<int, string>
 */
function qiniu110ExistingColumns($db, string $table): array
{
    if (!qiniu110TableExists($db, $table)) {
        throw new \RuntimeException('七牛抓取表不存在：' . $table);
    }
    return array_values(array_filter(array_column(
        $db->query('SHOW COLUMNS FROM `' . $table . '`'),
        'Field'
    ), 'is_string'));
}

/**
 * 获取已安装 qiniu 模块版本。
 *
 * @param mixed $db ThinkPHP 数据库连接
 * @return string 版本号；模块记录不存在时返回空字符串
 */
function qiniu110ModuleVersion($db): string
{
    return (string)($db->name('module')->where('module', 'qiniu')->value('version') ?: '');
}

/**
 * 统计字段中的非默认值，供 dry-run 展示潜在数据损失。
 *
 * @param mixed $db ThinkPHP 数据库连接
 * @param string $table 完整表名
 * @param array<int, string> $columns 待检查字段
 * @return array<string, int> 字段名到非默认行数的映射
 */
function qiniu110NonDefaultCounts($db, string $table, array $columns): array
{
    $numericColumns = ['fetch_wait', 'submitted_at'];
    $counts = [];
    foreach ($columns as $column) {
        $condition = in_array($column, $numericColumns, true)
            ? '`' . $column . '` <> 0'
            : "`" . $column . "` <> ''";
        $rows = $db->query(
            'SELECT COUNT(*) AS row_count FROM `' . $table . '` WHERE ' . $condition
        );
        $counts[$column] = (int)($rows[0]['row_count'] ?? 0);
    }
    return $counts;
}

/**
 * 执行 1.1.0 升级，按列检测，支持中断后重跑。
 *
 * @return void
 */
function upgradeQiniu110(): void
{
    $context = qiniu110MigrationContext();
    $db = $context['db'];
    $table = $context['table'];
    $existing = qiniu110ExistingColumns($db, $table);

    foreach (qiniu110ColumnDefinitions() as $column => $definition) {
        if (!in_array($column, $existing, true)) {
            $db->execute('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
            echo 'Added ' . $column . PHP_EOL;
        }
    }

    // 已升级的数据库也要补齐 wait 哨兵值含义，避免 SHOW CREATE TABLE 仍显示旧的模糊注释。
    $waitComment = '七牛异步抓取排队数：大于0为前方排队任务数，0为当前任务正在处理，-1为任务至少已处理过一次且可能进入重试，不代表成功或失败';
    $waitColumn = array_values(array_filter(
        $db->query('SHOW FULL COLUMNS FROM `' . $table . '`'),
        static function (array $column): bool {
            return ($column['Field'] ?? '') === 'fetch_wait';
        }
    ));
    if ($waitColumn && ($waitColumn[0]['Comment'] ?? '') !== $waitComment) {
        $db->execute(
            "ALTER TABLE `" . $table . "` MODIFY COLUMN `fetch_wait` int NOT NULL DEFAULT '0' COMMENT '" . $waitComment . "'"
        );
        echo 'Updated fetch_wait comment' . PHP_EOL;
    }

    $db->name('module')->where('module', 'qiniu')->update(['version' => '1.1.0']);
    echo "Qiniu 1.1.0 migration complete. Restart workers and clear the table schema cache.\n";
}

/**
 * 校验回退目标版本。
 *
 * @param string $targetVersion 回退后的模块版本
 * @return void
 */
function qiniu110ValidateTargetVersion(string $targetVersion): void
{
    if (!preg_match('/^\d+\.\d+\.\d+$/', $targetVersion)
        || version_compare($targetVersion, '1.1.0', '>=')) {
        throw new \RuntimeException('回退目标版本必须是合法的旧版本号，且不能为 1.1.0');
    }
}

/**
 * 打印回退影响范围。
 *
 * @param string $table 完整表名
 * @param string $moduleVersion 当前模块版本
 * @param string $targetVersion 回退目标版本
 * @param array<int, string> $existing 本次迁移字段中当前存在的字段
 * @param int $activeCount 仍处于抓取中的记录数
 * @param array<string, int> $nonDefaultCounts 非默认值统计
 * @return void
 */
function qiniu110PrintRollbackPlan(
    string $table,
    string $moduleVersion,
    string $targetVersion,
    array $existing,
    int $activeCount,
    array $nonDefaultCounts
): void {
    echo "Qiniu 1.1.0 rollback plan\n";
    echo 'Table: ' . $table . PHP_EOL;
    echo 'Module version: ' . ($moduleVersion !== '' ? $moduleVersion : '[missing]') . PHP_EOL;
    echo 'Target version: ' . $targetVersion . PHP_EOL;
    echo 'Columns to drop: ' . ($existing ? implode(', ', $existing) : '[none]') . PHP_EOL;
    echo 'Active fetch records: ' . $activeCount . PHP_EOL;
    foreach ($nonDefaultCounts as $column => $count) {
        echo 'Non-default values in ' . $column . ': ' . $count . PHP_EOL;
    }
    echo "No qiniu_fetch_file rows, queue messages, or Qiniu objects will be deleted.\n";
}

/**
 * 回滚 1.1.0 新增字段。
 *
 * 实际删除字段必须带 --force；不带参数或使用 --dry-run 时只检查并输出计划。
 *
 * @param string $targetVersion 回退后的模块版本
 * @param bool $dryRun 是否只预览
 * @param bool $force 是否确认执行破坏性 DDL
 * @return void
 */
function rollbackQiniu110(string $targetVersion = '1.0.5', bool $dryRun = false, bool $force = false): void
{
    qiniu110ValidateTargetVersion($targetVersion);
    $context = qiniu110MigrationContext();
    $db = $context['db'];
    $table = $context['table'];
    $allColumns = array_keys(qiniu110ColumnDefinitions());
    $currentColumns = qiniu110ExistingColumns($db, $table);
    $existing = array_values(array_intersect($currentColumns, $allColumns));
    $moduleVersion = qiniu110ModuleVersion($db);
    $activeCount = 0;
    if (in_array('fetch_status', $currentColumns, true)) {
        $activeCount = (int)$db->name('qiniu_fetch_file')
            ->where('fetch_status', 0)
            ->count();
    }
    $nonDefaultCounts = qiniu110NonDefaultCounts($db, $table, $existing);
    qiniu110PrintRollbackPlan(
        $table,
        $moduleVersion,
        $targetVersion,
        $existing,
        $activeCount,
        $nonDefaultCounts
    );

    if ($dryRun) {
        echo "Dry-run only; database was not changed.\n";
        return;
    }
    if (!$force) {
        throw new \RuntimeException(
            '实际回滚会删除 1.1.0 新增字段；请先执行 down --dry-run，再使用 down --force 确认'
        );
    }
    if ($moduleVersion === '' && $existing) {
        throw new \RuntimeException('qiniu 模块登记版本不存在，无法安全判断回滚来源；请先核对数据库或使用备份恢复');
    }
    if ($moduleVersion !== '' && $moduleVersion !== '1.1.0' && $moduleVersion !== $targetVersion) {
        throw new \RuntimeException(
            '当前 qiniu 模块版本为 ' . $moduleVersion . '，既不是 1.1.0 也不是目标版本 ' . $targetVersion
        );
    }
    if ($activeCount > 0) {
        echo "WARNING: 检测到进行中的抓取记录；请确认所有 qiniu Worker 已停止。\n";
    }
    if (array_sum($nonDefaultCounts) > 0) {
        echo "WARNING: 回滚会删除新增字段中的运行数据；这些数据不会写入旧表。\n";
    }

    foreach ($existing as $column) {
        $db->execute('ALTER TABLE `' . $table . '` DROP COLUMN `' . $column . '`');
        echo 'Dropped ' . $column . PHP_EOL;
    }
    $db->name('module')->where('module', 'qiniu')->update(['version' => $targetVersion]);
    echo 'Qiniu migration rolled back to ' . $targetVersion . ". Restart workers after deploying compatible code.\n";
}

/**
 * 打印命令用法。
 *
 * @return void
 */
function qiniu110PrintUsage(): void
{
    echo <<<USAGE
Usage:
  php app/qiniu/migrates/upgrade_1_1_0.php [up]
  php app/qiniu/migrates/upgrade_1_1_0.php down --dry-run [--to=1.0.5]
  php app/qiniu/migrates/upgrade_1_1_0.php down --force [--to=1.0.5]

USAGE;
}

/**
 * 解析命令行参数并执行迁移。
 *
 * @param array<int, string> $arguments 命令行参数
 * @return void
 */
function runQiniu110Migration(array $arguments): void
{
    $command = 'up';
    $dryRun = false;
    $force = false;
    $targetVersion = '1.0.5';

    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === 'up' || $argument === 'down') {
            if ($command !== 'up' && $command !== $argument) {
                throw new \RuntimeException('不能同时指定 up 和 down');
            }
            $command = $argument;
            continue;
        }
        if ($argument === '--dry-run') {
            $dryRun = true;
            continue;
        }
        if ($argument === '--force') {
            $force = true;
            continue;
        }
        if (strpos($argument, '--to=') === 0) {
            $targetVersion = substr($argument, 5);
            continue;
        }
        if ($argument === '--help' || $argument === '-h') {
            qiniu110PrintUsage();
            return;
        }
        throw new \RuntimeException('未知参数：' . $argument);
    }

    if ($command === 'up') {
        if ($dryRun || $force) {
            throw new \RuntimeException('up 不支持 --dry-run 或 --force');
        }
        upgradeQiniu110();
        return;
    }
    rollbackQiniu110($targetVersion, $dryRun, $force);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        runQiniu110Migration($argv ?? []);
    } catch (\Throwable $exception) {
        fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
