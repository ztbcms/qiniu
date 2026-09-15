<?php
/**
 * QINIU_LIVE_TEST=1 php app/qiniu/tests/fetch_live_test.php
 * 显式启用真实七牛验证，使用随机 key，仅清理本次成功生成的对象及记录。
 */
if (getenv('QINIU_LIVE_TEST') !== '1') { fwrite(STDERR,"Set QINIU_LIVE_TEST=1 to run.\n");exit(2); }
require dirname(__DIR__,3).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__,3).'/');$app->initialize();
$q=new app\qiniu\service\QiniuService();$key='qiniu-110-verification/'.bin2hex(random_bytes(16)).'.doc';
$start=microtime(true);$id=0;$completed=false;
try {
    $r=$q->createFetch('https://static.gongkaoleida.com/2026/attach/7685166/3dad5ac9c7c40317ba2a47043c56c10bfbcececd.doc',$key,'正常资源验证.doc','doc');
    if(!$r['status']){throw new RuntimeException($r['msg']);}$id=$r['data']['id'];
    $submitted = app\qiniu\model\QiniuFetchFileModel::find($id);
    $query = new ReflectionMethod($q, 'queryFetchTask');
    $queried = $query->invoke($q, $q->getConfig('bucket'), $submitted->fetch_task_id);
    if (!$queried['status'] || !isset($queried['data']['wait'])) { throw new RuntimeException('SDK live query failed: ' . ($queried['msg'] ?? 'missing wait')); }
    echo "SDK region discovery and task query passed\n";
    while(microtime(true)-$start<40){
        $row=app\qiniu\model\QiniuFetchFileModel::find($id);$check=$q->checkFetch($row);
        if(!$check['status'] || $check['data']['status']===2){throw new RuntimeException($check['msg']);}
        if($check['data']['status']===1){
            $meta=$q->doStatFile($q->getConfig('bucket'),$key);
            if(!$meta['status'] || $meta['data']['fsize']!==44544 || $meta['data']['mimeType']!=='application/msword'){throw new RuntimeException('Unexpected metadata');}
            $completed=true;echo json_encode(['status'=>'success','bytes'=>44544,'mime'=>'application/msword','seconds'=>round(microtime(true)-$start,3),'task_id_saved'=>(string)$row->fetch_task_id!=='']).PHP_EOL;break;
        }
        usleep(300000);
    }
    if(!$completed){throw new RuntimeException('Live test timeout; retained key: '.$key);}
} finally {
    if($completed){$r=$q->doDeleteFile($q->getConfig('bucket'),$key);if(!$r['status']){throw new RuntimeException('Cleanup failed: '.$key);}app\qiniu\model\QiniuFetchFileModel::where('id',$id)->delete();echo "Temporary object and record cleaned\n";}
}
