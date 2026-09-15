<?php
/** MySQL 迁移验证：随机测试表前缀，重复执行并验证历史数据，结束后清理测试表。 */
require dirname(__DIR__) . '/migrates/upgrade_1_1_0.php';
$original = think\facade\Db::connect()->getConfig();
$prefix = 'q110_test_' . bin2hex(random_bytes(4)) . '_';
$original['prefix'] = $prefix;
think\facade\Config::set(['default'=>'qiniu_migration_test','connections'=>['qiniu_migration_test'=>$original]], 'database');
$db=think\facade\Db::connect();
try {
    $db->execute('CREATE TABLE `'.$prefix.'qiniu_fetch_file` (id int PRIMARY KEY, fetch_status tinyint NOT NULL DEFAULT 0, marker varchar(30)) ENGINE=InnoDB');
    $db->execute('CREATE TABLE `'.$prefix.'module` (module varchar(15) PRIMARY KEY, version varchar(50)) ENGINE=InnoDB');
    $db->name('qiniu_fetch_file')->insert(['id'=>1,'fetch_status'=>1,'marker'=>'keep-existing-file']);
    $db->name('module')->insert(['module'=>'qiniu','version'=>'1.0.5']);
    upgradeQiniu110();upgradeQiniu110();
    $row=$db->name('qiniu_fetch_file')->find(1);
    if($row['marker']!=='keep-existing-file' || (int)$row['fetch_status']!==1 || $row['fetch_task_id']!=='' || $db->name('module')->value('version')!=='1.1.0'){
        throw new RuntimeException('Migration did not preserve data/version');
    }

    // dry-run 只输出计划，不修改字段或模块版本。
    rollbackQiniu110('1.0.5', true, false);
    $columns = array_column($db->query('SHOW COLUMNS FROM `'.$prefix.'qiniu_fetch_file`'), 'Field');
    if (!in_array('fetch_task_id', $columns, true) || $db->name('module')->value('version') !== '1.1.0') {
        throw new RuntimeException('Rollback dry-run changed the database');
    }

    // 新字段中的数据会被删除，但业务记录本身必须保留。
    $db->execute(
        'UPDATE `' . $prefix . 'qiniu_fetch_file`'
        . " SET `fetch_task_id` = 'task-to-drop', `fetch_wait` = -1,"
        . " `fetch_error` = 'temporary failure', `submitted_at` = " . time()
        . ", `callback_hash` = 'hash-to-drop' WHERE `id` = 1"
    );

    // 未显式确认时拒绝删除字段。
    try {
        rollbackQiniu110('1.0.5', false, false);
        throw new RuntimeException('Rollback without --force should fail');
    } catch (RuntimeException $exception) {
        if (strpos($exception->getMessage(), '--force') === false) {
            throw $exception;
        }
    }

    rollbackQiniu110('1.0.5', false, true);
    $columns = array_column($db->query('SHOW COLUMNS FROM `'.$prefix.'qiniu_fetch_file`'), 'Field');
    foreach (['fetch_task_id', 'fetch_wait', 'fetch_error', 'submitted_at', 'callback_hash'] as $column) {
        if (in_array($column, $columns, true)) {
            throw new RuntimeException('Rollback did not drop '.$column);
        }
    }
    $row = $db->name('qiniu_fetch_file')->find(1);
    if ($row['marker'] !== 'keep-existing-file' || (int)$row['fetch_status'] !== 1
        || $db->name('module')->value('version') !== '1.0.5') {
        throw new RuntimeException('Rollback did not preserve historical row or target version');
    }

    // 验证 down 后可以重新 up，且 up 仍然幂等。
    upgradeQiniu110();
    upgradeQiniu110();
    $columns = array_column($db->query('SHOW COLUMNS FROM `'.$prefix.'qiniu_fetch_file`'), 'Field');
    if (count(array_intersect(['fetch_task_id', 'fetch_wait', 'fetch_error', 'submitted_at', 'callback_hash'], $columns)) !== 5
        || $db->name('module')->value('version') !== '1.1.0') {
        throw new RuntimeException('Upgrade after rollback did not restore schema');
    }
    rollbackQiniu110('1.0.5', false, true);
    echo "Migration idempotence, rollback safety and historical data tests passed\n";
} finally {
    $db->execute('DROP TABLE IF EXISTS `'.$prefix.'qiniu_fetch_file`');
    $db->execute('DROP TABLE IF EXISTS `'.$prefix.'module`');
}
