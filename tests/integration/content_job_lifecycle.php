<?php

declare(strict_types=1);

$database = (string) getenv('MACCMS_TEST_DATABASE');
if (!preg_match('/^maccms_ci_[a-z0-9_]+$/', $database)) {
    fwrite(STDERR, "FAIL: lifecycle test requires a disposable maccms_ci_ database.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
define('APP_PATH', $root . '/application/');
define('ENTRANCE', 'command');
$_SERVER['HTTP_USER_AGENT'] = 'maccms-content-job-ci';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/tests/integration/content_job_lifecycle.php';
require $root . '/thinkphp/base.php';
\think\App::initCommon();

function queueAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

\think\Db::execute('DELETE FROM `mac_content_job_run`');
\think\Db::execute('DELETE FROM `mac_content_job`');

$repository = new \app\common\util\ContentJobRepository(static function (): int { return 1000; });
$job = $repository->enqueue('ai.normalize', ['vod_id' => 42], 'ci:vod:42', 10, 3, 900);
queueAssert((int) $job['job_id'] > 0, 'enqueue must persist a job.');

$claimed = $repository->claim('worker-a', 120, 1000);
queueAssert($claimed !== null && (int) $claimed['attempt'] === 1, 'first worker must claim attempt one.');
queueAssert($repository->claim('worker-b', 120, 1000) === null, 'second worker must not claim an active lease.');

$reclaimed = $repository->claim('worker-b', 120, 1121);
queueAssert($reclaimed !== null && (int) $reclaimed['attempt'] === 2, 'expired lease must be reclaimed as attempt two.');
queueAssert(!$repository->complete((int) $job['job_id'], 'worker-a', [], 1122), 'stale owner must not complete reclaimed work.');
queueAssert($repository->fail((int) $job['job_id'], 'worker-b', 'rate_limit', 'retry later', 1122), 'current owner must record failure.');

$afterFailure = $repository->find((int) $job['job_id']);
queueAssert($afterFailure['status'] === 'queued' && (int) $afterFailure['next_run_at'] === 1242, 'attempt two must retry with 120-second backoff.');
queueAssert($repository->claim('worker-c', 120, 1241) === null, 'job must not run before retry time.');
$third = $repository->claim('worker-c', 120, 1242);
queueAssert($third !== null && (int) $third['attempt'] === 3, 'job must run at retry time.');
queueAssert($repository->complete((int) $job['job_id'], 'worker-c', ['tokens' => 9], 1243), 'current owner must complete work.');

$final = $repository->find((int) $job['job_id']);
queueAssert($final['status'] === 'succeeded' && (int) $final['completed_at'] === 1243, 'job must finish succeeded.');
$runs = \think\Db::name('content_job_run')->where('job_id', (int) $job['job_id'])->order('attempt asc')->select();
queueAssert(count($runs) === 3, 'every claim must have one run record.');
queueAssert($runs[0]['error_class'] === 'lease_expired', 'reclaim must close the expired run.');
queueAssert($runs[1]['status'] === 'failed' && $runs[1]['error_class'] === 'rate_limit', 'retry failure must remain auditable.');
queueAssert($runs[2]['status'] === 'succeeded' && $runs[2]['metrics_json'] === '{"tokens":9}', 'success metrics must remain auditable.');

fwrite(STDOUT, "OK: real MySQL content-job lifecycle passed for {$database}.\n");
