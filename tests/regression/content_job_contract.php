<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$migrationPath = $root . '/application/data/migrations/20260918000200_content_jobs.sql';
$migration = is_file($migrationPath) ? (string) file_get_contents($migrationPath) : '';

$requiredSql = [
    'CREATE TABLE IF NOT EXISTS `__PREFIX__content_job`',
    'CREATE TABLE IF NOT EXISTS `__PREFIX__content_job_run`',
    '`job_type` varchar(64)',
    '`payload_json` mediumtext',
    '`status` varchar(16)',
    '`priority` int(11)',
    '`attempt` int(10) unsigned',
    '`max_attempts` int(10) unsigned',
    '`next_run_at` int(10) unsigned',
    '`lock_owner` varchar(128)',
    '`lock_expires_at` int(10) unsigned',
    '`idempotency_key` varchar(191)',
    '`error_class` varchar(128)',
    '`error_summary` varchar(1000)',
    'UNIQUE KEY `uk_type_idempotency` (`job_type`,`idempotency_key`)',
    'KEY `idx_claim` (`status`,`next_run_at`,`priority`,`job_id`)',
    'UNIQUE KEY `uk_job_attempt` (`job_id`,`attempt`)',
    'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
];

foreach ($requiredSql as $needle) {
    if (strpos($migration, $needle) === false) {
        fwrite(STDERR, "FAIL: content-job migration missing {$needle}\n");
        exit(1);
    }
}
if (substr_count($migration, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4') !== 2) {
    fwrite(STDERR, "FAIL: both content-job tables must use InnoDB/utf8mb4.\n");
    exit(1);
}
if (stripos($migration, 'FOREIGN KEY') !== false || preg_match('/\bmac_content_job\b/i', $migration)) {
    fwrite(STDERR, "FAIL: content-job migration must avoid core foreign keys and hard-coded prefixes.\n");
    exit(1);
}
if (stripos($migration, 'CREATE TABLE IF NOT EXISTS `__PREFIX__task`') !== false
    || stripos($migration, 'CREATE TABLE IF NOT EXISTS `__PREFIX__ext_sync_job`') !== false) {
    fwrite(STDERR, "FAIL: content jobs must not repurpose reward or provider-sync tables.\n");
    exit(1);
}

$requiredFiles = [
    $root . '/application/common/model/ContentJob.php',
    $root . '/application/common/model/ContentJobRun.php',
    $root . '/application/common/util/ContentJobRepository.php',
];
foreach ($requiredFiles as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, 'FAIL: missing content-job implementation file ' . basename($path) . "\n");
        exit(1);
    }
}

require_once $root . '/application/common/util/ContentJobRepository.php';

use app\common\util\ContentJobRepository;

final class MemoryContentJobRepository extends ContentJobRepository
{
    public $rows = [];
    public $nextId = 1;

    protected function lookupById(int $jobId): ?array
    {
        return $this->rows[$jobId] ?? null;
    }

    protected function lookupByKey(string $jobType, string $idempotencyKey): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['job_type'] === $jobType && $row['idempotency_key'] === $idempotencyKey) {
                return $row;
            }
        }
        return null;
    }

    protected function insertJob(array $row): int
    {
        $row['job_id'] = $this->nextId++;
        $this->rows[$row['job_id']] = $row;
        return $row['job_id'];
    }
}

function expectInvalid(callable $callback, string $label): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        return;
    }
    fwrite(STDERR, "FAIL: {$label} must be rejected.\n");
    exit(1);
}

$repository = new MemoryContentJobRepository(static function (): int {
    return 1700000000;
});
$created = $repository->enqueue('ai.normalize', ['vod_id' => 42], 'vod:42:normalize:v1', 25, 4, 1700000030);

$expected = [
    'job_type' => 'ai.normalize',
    'payload_json' => '{"vod_id":42}',
    'status' => 'queued',
    'priority' => 25,
    'attempt' => 0,
    'max_attempts' => 4,
    'next_run_at' => 1700000030,
    'lock_owner' => '',
    'lock_expires_at' => 0,
    'idempotency_key' => 'vod:42:normalize:v1',
    'error_class' => '',
    'error_summary' => '',
    'created_at' => 1700000000,
    'updated_at' => 1700000000,
];
foreach ($expected as $field => $value) {
    if (($created[$field] ?? null) !== $value) {
        fwrite(STDERR, "FAIL: enqueue produced an unexpected {$field}.\n");
        exit(1);
    }
}
if (($created['job_id'] ?? 0) !== 1 || $repository->find(1) !== $created) {
    fwrite(STDERR, "FAIL: enqueue/find must return the persisted job.\n");
    exit(1);
}

$same = $repository->enqueue('ai.normalize', ['vod_id' => 999], 'vod:42:normalize:v1');
if ($same !== $created || count($repository->rows) !== 1) {
    fwrite(STDERR, "FAIL: a repeated idempotency key must return one logical job.\n");
    exit(1);
}

expectInvalid(function () use ($repository): void {
    $repository->enqueue('Bad Type', [], 'key');
}, 'invalid job type');
expectInvalid(function () use ($repository): void {
    $repository->enqueue('ai.normalize', [], '');
}, 'empty idempotency key');
expectInvalid(function () use ($repository): void {
    $repository->enqueue('ai.normalize', [], 'key', 0, 0);
}, 'zero maximum attempts');
expectInvalid(function () use ($repository): void {
    $repository->find(0);
}, 'non-positive job ID');

$invalidPayload = ['stream' => fopen('php://memory', 'rb')];
expectInvalid(function () use ($repository, $invalidPayload): void {
    $repository->enqueue('ai.normalize', $invalidPayload, 'invalid-json');
}, 'non-JSON payload');
fclose($invalidPayload['stream']);

fwrite(STDOUT, "OK: dedicated content-job schema and repository contract passed.\n");
