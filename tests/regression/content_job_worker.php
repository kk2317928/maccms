<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$migration = @file_get_contents($root . '/application/data/migrations/20260918000300_content_worker_heartbeat.sql') ?: '';
foreach (['CREATE TABLE IF NOT EXISTS `__PREFIX__content_worker_heartbeat`', 'PRIMARY KEY (`worker_id`)', 'KEY `idx_seen` (`last_seen_at`)'] as $needle) {
    if (strpos($migration, $needle) === false) {
        fwrite(STDERR, "FAIL: worker heartbeat migration missing {$needle}\n");
        exit(1);
    }
}

$workerPath = $root . '/application/common/util/ContentJobWorker.php';
if (!is_file($workerPath)) {
    fwrite(STDERR, "FAIL: ContentJobWorker is missing.\n");
    exit(1);
}
require_once $workerPath;
$failurePath = $root . '/application/common/util/ContentJobFailure.php';
if (!is_file($failurePath)) {
    fwrite(STDERR, "FAIL: ContentJobFailure is missing.\n");
    exit(1);
}
require_once $failurePath;

use app\common\util\ContentJobWorker;
use app\common\util\ContentJobFailure;

final class WorkerFakeRepository
{
    public $jobs = [];
    public $completed = [];
    public $failed = [];
    public $heartbeats = [];

    public function claim(string $workerId, int $leaseSeconds, int $now): ?array
    {
        return array_shift($this->jobs);
    }
    public function complete(int $jobId, string $workerId, array $metrics, int $now): bool
    {
        $this->completed[] = [$jobId, $metrics];
        return true;
    }
    public function fail(int $jobId, string $workerId, string $class, string $summary, int $now): bool
    {
        $this->failed[] = [$jobId, $class, $summary];
        return true;
    }
    public function heartbeat(string $workerId, string $status, int $processed, int $now): void
    {
        $this->heartbeats[] = [$status, $processed, $now];
    }
}

function workerAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$ticks = [1000, 1000, 1001, 1002, 1003, 1004];
$clock = static function () use (&$ticks): int { return array_shift($ticks) ?? 1004; };
$repository = new WorkerFakeRepository();
$repository->jobs = [
    ['job_id' => 1, 'job_type' => 'ok', 'payload_json' => '{"value":2}'],
    ['job_id' => 2, 'job_type' => 'broken', 'payload_json' => '{}'],
    ['job_id' => 3, 'job_type' => 'ok', 'payload_json' => '{"value":4}'],
];
$worker = new ContentJobWorker($repository, [
    'ok' => static function (array $payload): array { return ['value' => $payload['value']]; },
    'broken' => static function (): array { throw new RuntimeException('secret-token must not leak'); },
], $clock);
$result = $worker->run('ci-worker', 2, 30, 120);
workerAssert($result['processed'] === 2 && $result['succeeded'] === 1 && $result['failed'] === 1, 'worker must obey max-jobs and report outcomes.');
workerAssert(count($repository->jobs) === 1, 'worker must leave jobs beyond its count budget.');
workerAssert($repository->completed[0] === [1, ['value' => 2]], 'handler metrics must be recorded on completion.');
workerAssert($repository->failed[0][1] === 'handler_error' && $repository->failed[0][2] === 'Job handler failed.', 'handler errors must use a redacted summary.');
workerAssert($repository->heartbeats[0][0] === 'running' && end($repository->heartbeats)[0] === 'idle', 'worker must emit running and idle heartbeats.');

$command = @file_get_contents($root . '/application/command/MaccmsJobs.php') ?: '';
foreach (['maccms:jobs', "'max-jobs'", "'max-seconds'", "'lease-seconds'", "'worker'"] as $needle) {
    if (strpos($command, $needle) === false) {
        fwrite(STDERR, "FAIL: jobs CLI missing {$needle}\n");
        exit(1);
    }
}

$rateRepository = new WorkerFakeRepository();
$rateRepository->jobs = [['job_id' => 4, 'job_type' => 'limited', 'payload_json' => '{}']];
$rateWorker = new ContentJobWorker($rateRepository, [
    'limited' => static function (): array { throw new ContentJobFailure('rate_limit', 'External provider rate limit reached.'); },
], static fn (): int => 2000);
$rateWorker->run('rate-worker', 1, 30, 120);
workerAssert($rateRepository->failed[0][1] === 'rate_limit', 'typed provider rate limits must remain observable in job-run metrics.');
workerAssert($rateRepository->failed[0][2] === 'External provider rate limit reached.', 'typed job failures must expose only their safe summary.');

$httpRepository = new WorkerFakeRepository();
$httpRepository->jobs = [['job_id' => 5, 'job_type' => 'http-limited', 'payload_json' => '{}']];
$httpWorker = new ContentJobWorker($httpRepository, [
    'http-limited' => static function (): array { throw new RuntimeException('provider response must stay redacted', 429); },
], static fn (): int => 3000);
$httpWorker->run('http-rate-worker', 1, 30, 120);
workerAssert($httpRepository->failed[0][1] === 'rate_limit', 'HTTP 429 exceptions must remain observable as rate limits.');
workerAssert($httpRepository->failed[0][2] === 'External provider rate limit reached.', 'HTTP 429 details must be redacted.');

fwrite(STDOUT, "OK: bounded content-job worker contract passed.\n");
