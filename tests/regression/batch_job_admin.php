<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$servicePath = $root . '/application/common/util/ContentJobAdminService.php';
if (!is_file($servicePath)) {
    fwrite(STDERR, "FAIL: ContentJobAdminService.php is missing.\n");
    exit(1);
}

require_once $root . '/application/common/util/ContentAdminPolicy.php';
require_once $root . '/application/common/util/ContentAdminAudit.php';
require_once $servicePath;

use app\common\util\ContentAdminAudit;
use app\common\util\ContentJobAdminService;

function batchAdminAssert($condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}

$queued = [];
$events = [];
$jobs = [
    91 => ['job_id' => 91, 'job_type' => 'ai.normalize', 'status' => 'failed', 'attempt' => 3, 'max_attempts' => 3, 'error_summary' => 'token=very-secret upstream failed'],
    92 => ['job_id' => 92, 'job_type' => 'tmdb_match', 'status' => 'running', 'attempt' => 1, 'max_attempts' => 3, 'error_summary' => ''],
];
$audit = new ContentAdminAudit(
    static function (array $row) use (&$events): int { $events[] = $row; return count($events); },
    static fn (): int => 1726800000
);
$service = new ContentJobAdminService(
    $audit,
    static function (string $type, array $payload, string $key) use (&$queued): array {
        foreach ($queued as $row) {
            if ($row['job_type'] === $type && $row['idempotency_key'] === $key) { return $row; }
        }
        $row = ['job_id' => count($queued) + 1, 'job_type' => $type, 'payload_json' => json_encode($payload), 'idempotency_key' => $key, 'status' => 'queued'];
        $queued[] = $row;
        return $row;
    },
    static function (string $type, string $status, int $offset, int $limit) use (&$jobs): array {
        $rows = array_values(array_filter($jobs, static fn (array $row): bool =>
            ($type === '' || $row['job_type'] === $type) && ($status === '' || $row['status'] === $status)
        ));
        return ['rows' => array_slice($rows, $offset, $limit), 'total' => count($rows)];
    },
    static fn (int $jobId, int $limit): array => [['run_id' => 7, 'job_id' => $jobId, 'attempt' => 3, 'status' => 'failed', 'error_summary' => 'api_key=leaked']],
    static function (int $jobId, callable $authorize) use (&$jobs): array {
        if (!isset($jobs[$jobId])) { throw new RuntimeException('Job not found.'); }
        $authorize($jobs[$jobId]);
        if ($jobs[$jobId]['status'] !== 'failed') { throw new RuntimeException('Only terminal failed jobs can be retried.'); }
        $jobs[$jobId] = array_merge($jobs[$jobId], [
            'status' => 'queued', 'attempt' => (int) $jobs[$jobId]['attempt'], 'max_attempts' => max((int) $jobs[$jobId]['attempt'], (int) $jobs[$jobId]['max_attempts']) + 1, 'next_run_at' => 1726800000,
            'lock_owner' => '', 'lock_expires_at' => 0, 'error_class' => '',
            'error_summary' => '', 'completed_at' => 0,
        ]);
        return $jobs[$jobId];
    },
    static fn (callable $callback) => $callback(),
    static fn (): int => 1726800000
);

$result = $service->enqueueBatch('ai.normalize', [7, '7', 4], 9, 'editor', ['content_workspace/run_ai'], true);
batchAdminAssert($result['requested'] === 2 && count($queued) === 2, 'batch enqueue must normalize and deduplicate explicit positive video IDs.');
batchAdminAssert($queued[0]['idempotency_key'] === 'admin-batch:ai.normalize:vod:4' && $queued[1]['idempotency_key'] === 'admin-batch:ai.normalize:vod:7', 'batch enqueue must use deterministic idempotency keys.');
$service->enqueueBatch('ai.normalize', [4, 7], 9, 'editor', ['content_workspace/run_ai'], true);
batchAdminAssert(count($queued) === 2, 'repeated batch submission must not create duplicate jobs.');
$service->enqueueBatch('tmdb.match', [8], 9, 'editor', ['content_workspace/run_tmdb'], true);
batchAdminAssert($queued[2]['job_type'] === 'tmdb_match', 'TMDB batch requests must map to the registered Cron job type.');

foreach ([
    ['ai.normalize', [1], [], true],
    ['tmdb.match', [1], ['content_workspace/run_tmdb'], false],
] as $denied) {
    try {
        $service->enqueueBatch($denied[0], $denied[1], 9, 'editor', $denied[2], $denied[3]);
        batchAdminAssert(false, 'batch enqueue bypassed exact permission or confirmation.');
    } catch (RuntimeException $exception) {}
}
try {
    $service->enqueueBatch('ai.normalize', range(1, 101), 9, 'editor', ['content_workspace/run_ai'], true);
    batchAdminAssert(false, 'batch enqueue accepted more than 100 IDs.');
} catch (InvalidArgumentException $exception) {}
try {
    $service->enqueueBatch('unknown', [1], 9, 'editor', ['content_workspace/run_ai'], true);
    batchAdminAssert(false, 'batch enqueue accepted an unknown job type.');
} catch (InvalidArgumentException $exception) {}

$page = $service->page('', 'failed', 1, 20);
batchAdminAssert($page['total'] === 1 && strpos($page['rows'][0]['error_summary'], 'very-secret') === false, 'job page must filter status and redact error summaries.');
$runs = $service->runs(91, 10);
batchAdminAssert(strpos($runs[0]['error_summary'], 'leaked') === false, 'run history must redact error summaries.');

$retried = $service->retry(91, 9, 'editor', ['content_workspace/run_ai'], true);
batchAdminAssert($retried['status'] === 'queued' && $retried['attempt'] === 3 && $retried['max_attempts'] === 4 && $retried['lock_owner'] === '' && $retried['error_summary'] === '', 'retry must preserve the monotonic attempt identifier, add one retry allowance, and reset lease, error and terminal fields.');
batchAdminAssert(count($runs) === 1, 'retry must preserve historical content_job_run rows.');
$migration = @file_get_contents($root . '/application/data/migrations/20260918000200_content_jobs.sql') ?: '';
$repository = @file_get_contents($root . '/application/common/util/ContentJobRepository.php') ?: '';
batchAdminAssert(strpos($migration, 'UNIQUE KEY `uk_job_attempt` (`job_id`, `attempt`)') !== false, 'run history must retain unique monotonic attempt identifiers.');
batchAdminAssert(strpos($repository, "'attempt' => (int) \$job['attempt']") !== false, 'claim recording must bind history to the monotonic job attempt.');
try {
    $service->retry(92, 9, 'editor', ['content_workspace/run_tmdb'], true);
    batchAdminAssert(false, 'retry accepted a non-terminal job.');
} catch (RuntimeException $exception) {}
batchAdminAssert(count($events) === 4, 'enqueue and retry must append immutable audit events.');
batchAdminAssert($events[0]['event_code'] === 'content.job.batch_enqueued' && $events[3]['event_code'] === 'content.job.retried', 'batch actions must use stable audit event codes.');

$controller = @file_get_contents($root . '/application/admin/controller/ContentWorkspace.php') ?: '';
$template = @file_get_contents($root . '/application/admin/view_new/content_workspace/jobs.html') ?: '';
$dashboard = @file_get_contents($root . '/application/admin/view_new/content_workspace/index.html') ?: '';
foreach (['function jobs', 'mac_admin_csrf_token', 'enqueueBatch', '->retry(', 'confirmed'] as $needle) {
    batchAdminAssert(strpos($controller, $needle) !== false, 'batch job controller contract missing ' . $needle . '.');
}
foreach (['|htmlentities', 'name="vod_ids"', 'name="job_type"', 'name="status"', 'name="confirmed"', '批量入隊', '失敗重試', '執行紀錄', 'data-confirm'] as $needle) {
    batchAdminAssert(strpos($template, $needle) !== false, 'batch job view contract missing ' . $needle . '.');
}
batchAdminAssert(strpos($dashboard, 'content_workspace/jobs') !== false, 'workspace dashboard must link to batch job administration.');
foreach (['TmdbExternalSourceProvider', 'AiNormalizationJobHandler', '->match(', '->handle('] as $needle) {
    batchAdminAssert(strpos($controller, $needle) === false, 'HTTP controller must enqueue only and never invoke external handlers: ' . $needle . '.');
}

fwrite(STDOUT, "OK: batch enqueue, failure inspection and retry UI contract passed.\n");
