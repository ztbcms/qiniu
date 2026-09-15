<?php

/** 七牛 1.1.0 抓取生命周期测试：独立内存数据库，不使用真实七牛和业务表。 */
require dirname(__DIR__, 3) . '/vendor/autoload.php';
$app = new think\App(dirname(__DIR__, 3) . '/');
$app->initialize();
think\facade\Config::set(['default' => 'sqlite', 'connections' => ['sqlite' => [
    'type' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'fields_strict' => false,
]]], 'database');
think\facade\Db::execute("CREATE TABLE qiniu_fetch_file (
 id INTEGER PRIMARY KEY AUTOINCREMENT, uuid TEXT, bucket TEXT, key TEXT,
 file_name TEXT, file_ext TEXT, file_url TEXT, fetch_url TEXT,
 fetch_status INTEGER DEFAULT 0, file_type TEXT DEFAULT '', file_size INTEGER DEFAULT 0,
 fetch_task_id TEXT DEFAULT '', fetch_wait INTEGER DEFAULT 0, fetch_error TEXT DEFAULT '',
 submitted_at INTEGER DEFAULT 0, callback_hash TEXT DEFAULT '', create_time INTEGER, update_time INTEGER
)");

/** 可控网络边界，验证真实服务与 ORM 状态转换。 */
class FakeFetchService extends app\qiniu\service\QiniuService
{
    /** @var int 提交次数 */
    public $submissions = 0;
    /** @var array 模拟 stat */
    public $statResult = ['status' => false, 'data' => ['http_code' => 612], 'msg' => 'missing'];
    /** @var array 模拟任务状态 */
    public $queryResult = ['status' => true, 'data' => ['wait' => -1], 'msg' => 'queried'];
    /** @var string 当前回调凭证 */
    public $token = '';
    /** @var bool 是否模拟提交超时 */
    public $timeout = false;
    /** @var mixed 可覆盖的确认宽限期 */
    public $confirmTimeout = null;
    /** @var callable|null 模拟 stat 期间的并发写入 */
    public $onStat;
    /** @return array 测试配置 */
    public function config() { return ['bucket' => 'test', 'fetch' => ['submit_confirm_timeout' => $this->confirmTimeout, 'domain' => 'https://cdn.example.com', 'prefix_key' => 'fetch/', 'callback_url' => 'https://api.example.com/qiniu/fetch/callback']]; }
    /** @param string $bucket 空间 @param string $key 键 @return array */
    public function doStatFile($bucket, $key) { if ($this->onStat !== null) { $hook = $this->onStat; $this->onStat = null; $hook(); } return $this->statResult; }
    /** @param string $bucket 空间 @param string $id 任务 @return array */
    protected function queryFetchTask(string $bucket, string $id): array { return $this->queryResult; }
    /** @param string $bucket 空间 @param string $key 键 @param string $url 来源 @param string $callback 回调 @return array */
    protected function submitFetch(string $bucket, string $key, string $url, string $callback): array
    {
        ++$this->submissions;
        parse_str(parse_url($callback, PHP_URL_QUERY), $params);
        $this->token = $params['token'];
        return $this->timeout ? ['status' => false, 'msg' => 'network timeout', 'data' => ['definitive' => false]]
            : ['status' => true, 'data' => ['id' => 'task-' . $this->submissions, 'wait' => 2]];
    }
}

/** @param bool $condition 条件 @param string $message 说明 @return void */
function verifyFetch(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
    echo 'PASS ' . $message . PHP_EOL;
}
$q = new FakeFetchService();
$r = $q->createFetch('https://source.example.com/a.doc', 'a.doc', 'a.doc', 'doc');
$id = $r['data']['id'];
$m = app\qiniu\model\QiniuFetchFileModel::find($id);
verifyFetch($m->fetch_task_id === 'task-1', 'persist cloud task id');
$r = $q->checkFetch($m);
verifyFetch($r['data']['status'] === 0, 'wait=-1 must remain pending');
$q->createFetch('https://source.example.com/a.doc', 'a.doc', 'a.doc', 'doc');
verifyFetch($q->submissions === 1, 'pending retry does not resubmit');
$oldToken = $q->token;
verifyFetch(!$q->acceptFetchCallback($id, str_repeat('0', 64), ['bucket'=>'test','key'=>'a.doc','err'=>'404']), 'forged callback rejected');
verifyFetch(!$q->acceptFetchCallback($id, $oldToken, ['bucket'=>'other','key'=>'a.doc','err'=>'404']), 'callback bucket must match');
verifyFetch($q->acceptFetchCallback($id, $oldToken, ['bucket'=>'test','key'=>'a.doc','err'=>'source returns 404 https://secret/?token=x','code'=>404]), 'failure callback accepted');
$r=$q->checkFetch(app\qiniu\model\QiniuFetchFileModel::find($id));
verifyFetch($r['data']['status']===2 && strpos($r['msg'],'secret')===false, 'failure terminal and redacted reason');
$q->createFetch('https://source.example.com/a.doc', 'a.doc', 'a.doc', 'doc');
verifyFetch($q->submissions===2, 'failed retry resubmits');
verifyFetch(!$q->acceptFetchCallback($id,$oldToken,['bucket'=>'test','key'=>'a.doc','err'=>'late']), 'old callback cannot overwrite new attempt');
$q->statResult=['status'=>true,'data'=>['fsize'=>44544,'mimeType'=>'application/msword']];
$q->checkFetch(app\qiniu\model\QiniuFetchFileModel::find($id));
$q->acceptFetchCallback($id,$q->token,['bucket'=>'test','key'=>'a.doc','err'=>'late']);
verifyFetch((int)app\qiniu\model\QiniuFetchFileModel::find($id)->fetch_status===1, 'late failure cannot overwrite success');
$q->createFetch('https://source.example.com/a.doc', 'a.doc', 'a.doc', 'doc');
verifyFetch($q->submissions===2, 'success reused');
$q->statResult=['status'=>false,'data'=>['http_code'=>612],'msg'=>'missing'];
$q->timeout=true;
$r=$q->createFetch('https://source.example.com/b.doc', 'b.doc', 'b.doc','doc');
verifyFetch(!$r['status'], 'submit timeout surfaces error');
$q->createFetch('https://source.example.com/b.doc', 'b.doc', 'b.doc','doc');
verifyFetch($q->submissions===3, 'uncertain submission has cooldown');
$q->timeout=false;
$m=app\qiniu\model\QiniuFetchFileModel::where('key','b.doc')->find();
$m->save(['submitted_at'=>time()-31]);
$q->createFetch('https://source.example.com/b.doc', 'b.doc', 'b.doc','doc');
verifyFetch($q->submissions===4, 'legacy or uncertain record can recover after cooldown');
$q->statResult=['status'=>false,'data'=>['http_code'=>401],'msg'=>'storage auth failed'];
$r=$q->checkFetch(app\qiniu\model\QiniuFetchFileModel::where('key','b.doc')->find());
verifyFetch(!$r['status'] && $r['msg']==='storage auth failed', 'storage errors not disguised as pending');
// 明确复用查询不执行提交，错误不能伪装成“没有可复用记录”。
$before=$q->submissions;
$reused = $q->findReusableFetch('a.doc');
verifyFetch($reused['status'] && (int)$reused['data']['id'] === (int)$id, 'completed record reusable without source access');
verifyFetch(!$q->findReusableFetch('b.doc')['status'], 'reuse lookup preserves storage error');
$missing = $q->findReusableFetch('new.doc');
verifyFetch($missing['status'] && $missing['data']['id'] === null && $q->submissions === $before,
    'missing reuse lookup does not submit');
$q->statResult = ['status'=>false,'data'=>['http_code'=>612],'msg'=>'missing'];
verifyFetch($q->findReusableFetch('b.doc')['data']['id'] !== null, 'pending record reusable');
$q->createFetch('https://source.example.com/new.doc', 'new.doc', 'new.doc', 'doc');
$before = $q->submissions;
$q->createFetch('https://source.example.com/new.doc', 'new.doc', 'new.doc', 'doc');
verifyFetch($q->submissions === $before, 'submission rechecks record created after initial lookup');
$q->acceptFetchCallback((int)$q->findReusableFetch('new.doc')['data']['id'], $q->token,
    ['bucket'=>'test','key'=>'new.doc','err'=>'failed','code'=>404]);
verifyFetch($q->findReusableFetch('new.doc')['data']['id'] === null && $q->submissions === $before,
    'failed lookup does not resubmit');
$q->statResult = ['status'=>true,'data'=>['fsize'=>44544,'mimeType'=>'application/msword']];
verifyFetch($q->findReusableFetch('new.doc')['data']['id'] !== null && $q->submissions === $before,
    'failed record with late object reused');


/** @param int $age 提交至今秒数 @return app\qiniu\model\QiniuFetchFileModel */
function uncertainFetch(int $age): app\qiniu\model\QiniuFetchFileModel
{
    static $sequence = 0;
    return app\qiniu\model\QiniuFetchFileModel::create([
        'bucket' => 'test', 'key' => 'uncertain-' . ++$sequence . '.doc',
        'fetch_status' => 0, 'fetch_task_id' => '', 'submitted_at' => time() - $age,
        'callback_hash' => 'attempt-' . $sequence,
    ]);
}
$q = new FakeFetchService();
foreach ([10 => 0, 31 => 2] as $age => $expected) {
    $m = uncertainFetch($age);
    $r = $q->checkFetch($m);
    verifyFetch($r['status'] && $r['data']['status'] === $expected, "default 30 second grace age=$age");
}
$m = uncertainFetch(31);
$r = $q->checkFetch($m);
verifyFetch(strpos($r['msg'], '提交结果未确认') !== false, 'expiry has explicit uncertain outcome message');
$m = uncertainFetch(0);
$m->save(['submitted_at' => 0]);
$r = $q->checkFetch($m);
verifyFetch($r['data']['status'] === 2 && strpos($r['msg'], '缺少提交时间') !== false, 'legacy record expires');
foreach ([null, 0, -1, 'invalid'] as $timeout) {
    $q->confirmTimeout = $timeout;
    verifyFetch($q->checkFetch(uncertainFetch(31))['data']['status'] === 2, 'invalid or missing timeout defaults to 30');
}
$q->confirmTimeout = 60;
verifyFetch($q->checkFetch(uncertainFetch(40))['data']['status'] === 0, 'custom grace respected');
$q->confirmTimeout = null;
// 显式传入时间验证精确边界，不依赖墙上时钟跨秒。
$method = new ReflectionMethod($q, 'isSubmitConfirmationExpired');
$method->setAccessible(true);
$m = uncertainFetch(0);
verifyFetch(!$method->invoke($q, $m, (int)$m->submitted_at + 29)
    && $method->invoke($q, $m, (int)$m->submitted_at + 30), 'exact 30 second boundary');
$m = uncertainFetch(1000);
$m->save(['fetch_task_id' => 'known-task']);
verifyFetch($q->checkFetch($m)['data']['status'] === 0, 'known task does not expire via missing ID rule');
foreach ([401, 0, 503] as $code) {
    $q->statResult = ['status' => false, 'data' => ['http_code' => $code], 'msg' => 'storage error'];
    $m = uncertainFetch(31);
    verifyFetch(!$q->checkFetch($m)['status'] && (int)$m->refresh()->fetch_status === 0, 'stat error preserves pending');
}
$q->statResult = ['status' => false, 'data' => ['http_code' => 404], 'msg' => 'missing'];
foreach ([['fetch_task_id' => 'late-id'], ['callback_hash' => 'new-attempt'],
    ['submitted_at' => time()], ['fetch_status' => 1], ['fetch_status' => 2]] as $change) {
    $m = uncertainFetch(31);
    /** 模拟网络查询期间数据库被另一个请求修改。 */
    $q->onStat = function () use ($m, $change): void {
        app\qiniu\model\QiniuFetchFileModel::where('id', $m->id)->update($change);
    };
    $r = $q->checkFetch($m);
    foreach ($change as $field => $value) {
        verifyFetch((string)$m->$field === (string)$value, 'concurrent change preserved: ' . $field);
    }
    verifyFetch((string)$m->fetch_error === '', 'stale expiry cannot write error');
    if (($change['fetch_status'] ?? null) === 2) {
        verifyFetch($r['msg'] === '抓取失败', 'concurrent failure gets terminal message');
    }
}
$m = uncertainFetch(31);
$q->checkFetch($m);
$q->statResult = ['status' => true, 'data' => ['fsize' => 1024, 'mimeType' => 'application/msword']];
$q->createFetch('https://source.example.com/late.doc', $m->key, 'late.doc', 'doc');
verifyFetch($q->submissions === 0 && (int)$m->refresh()->fetch_status === 1, 'late object reused after expiry');
verifyFetch($q->checkFetch(uncertainFetch(31))['data']['status'] === 1, 'existing object wins over expiry');
echo "Qiniu fetch lifecycle tests passed\n";
