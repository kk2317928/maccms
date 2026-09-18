<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/application/common/util/ContentJobRepository.php';

use app\common\util\ContentJobRepository;

final class LifecycleMemoryRepository extends ContentJobRepository
{
    public $rows = [];
    public $runs = [];

    protected function lookupById(int $jobId): ?array
    {
        return $this->rows[$jobId] ?? null;
    }

    protected function claimOne(string $workerId, int $now, int $leaseExpiresAt): ?int
    {
        $eligible = array_filter($this->rows, static function (array $row) use ($now): bool {
            $queued = $row['status'] === 'queued' && $row['next_run_at'] <= $now;
            $expired = $row['status'] === 'running' && $row['lock_expires_at'] <= $now;
            return ($queued || $expired) && $row['attempt'] < $row['max_attempts'];
        });
        usort($eligible, static function (array $left, array $right): int {
            return [$right['priority'], $left['next_run_at'], $left['job_id']]
                <=> [$left['priority'], $right['next_run_at'], $right['job_id']];
        });
        if (!$eligible) {
            return null;
        }
        $jobId = $eligible[0]['job_id'];
        $this->rows[$jobId]['status'] = 'running';
        $this->rows[$jobId]['attempt']++;
        $this->rows[$jobId]['lock_owner'] = $workerId;
        $this->rows[$jobId]['lock_expires_at'] = $leaseExpiresAt;
        $this->rows[$jobId]['updated_at'] = $now;
        return $jobId;
    }

    protected function recordClaim(array $job, string $workerId, int $now): void
    {
        $this->runs[$job['job_id'] . ':' . $job['attempt']] = [
            'job_id' => $job['job_id'], 'attempt' => $job['attempt'], 'status' => 'running',
            'worker_id' => $workerId, 'started_at' => $now, 'finished_at' => 0,
        ];
    }

    protected function completeOne(int $jobId, string $workerId, int $now, string $metricsJson): bool
    {
        $row = $this->rows[$jobId] ?? null;
        if (!$row || $row['status'] !== 'running' || $row['lock_owner'] !== $workerId) {
            return false;
        }
        $this->rows[$jobId] = array_merge($row, [
            'status' => 'succeeded', 'lock_owner' => '', 'lock_expires_at' => 0,
            'completed_at' => $now, 'updated_at' => $now,
        ]);
        $key = $jobId . ':' . $row['attempt'];
        $this->runs[$key] = array_merge($this->runs[$key], [
            'status' => 'succeeded', 'finished_at' => $now, 'metrics_json' => $metricsJson,
        ]);
        return true;
    }

    protected function failOne(int $jobId, string $workerId, int $now, string $errorClass, string $summary): bool
    {
        $row = $this->rows[$jobId] ?? null;
        if (!$row || $row['status'] !== 'running' || $row['lock_owner'] !== $workerId) {
            return false;
        }
        $terminal = $row['attempt'] >= $row['max_attempts'];
        $delay = min(3600, 60 * (2 ** max(0, $row['attempt'] - 1)));
        $this->rows[$jobId] = array_merge($row, [
            'status' => $terminal ? 'failed' : 'queued',
            'next_run_at' => $terminal ? $row['next_run_at'] : $now + $delay,
            'lock_owner' => '', 'lock_expires_at' => 0,
            'error_class' => $errorClass, 'error_summary' => $summary,
            'completed_at' => $terminal ? $now : 0, 'updated_at' => $now,
        ]);
        $key = $jobId . ':' . $row['attempt'];
        $this->runs[$key] = array_merge($this->runs[$key], [
            'status' => 'failed', 'finished_at' => $now,
            'error_class' => $errorClass, 'error_summary' => $summary,
        ]);
        return true;
    }
}

function lifecycleAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$repository = new LifecycleMemoryRepository(static function (): int { return 1000; });
$repository->rows = [
    1 => ['job_id' => 1, 'status' => 'queued', 'priority' => 5, 'attempt' => 0, 'max_attempts' => 3, 'next_run_at' => 900, 'lock_owner' => '', 'lock_expires_at' => 0],
    2 => ['job_id' => 2, 'status' => 'queued', 'priority' => 20, 'attempt' => 0, 'max_attempts' => 2, 'next_run_at' => 900, 'lock_owner' => '', 'lock_expires_at' => 0],
];

$first = $repository->claim('worker-a', 120, 1000);
lifecycleAssert($first['job_id'] === 2 && $first['attempt'] === 1, 'claim must select the highest-priority eligible job.');
lifecycleAssert($repository->claim('worker-b', 120, 1000)['job_id'] === 1, 'a running unexpired job must not be claimed twice.');
lifecycleAssert($repository->claim('worker-c', 120, 1000) === null, 'no eligible job must return null.');

$reclaimed = $repository->claim('worker-c', 120, 1121);
lifecycleAssert($reclaimed['job_id'] === 2 && $reclaimed['attempt'] === 2, 'an expired lease must be reclaimable as the next attempt.');
lifecycleAssert($repository->complete(2, 'worker-a', ['tokens' => 3], 1122) === false, 'a stale owner must not complete a reclaimed job.');
lifecycleAssert($repository->complete(2, 'worker-c', ['tokens' => 3], 1122) === true, 'the current owner must complete a running job.');
lifecycleAssert($repository->rows[2]['status'] === 'succeeded' && $repository->runs['2:2']['status'] === 'succeeded', 'completion must update job and run audit.');

lifecycleAssert($repository->fail(1, 'worker-b', 'rate_limit', 'retry later', 1001) === true, 'current owner must be able to fail an attempt.');
lifecycleAssert($repository->rows[1]['status'] === 'queued' && $repository->rows[1]['next_run_at'] === 1061, 'first failure must retry after 60 seconds.');
$repository->claim('worker-d', 120, 1061);
$repository->fail(1, 'worker-d', 'timeout', 'second failure', 1062);
lifecycleAssert($repository->rows[1]['next_run_at'] === 1182, 'second failure must use exponential backoff.');
$repository->claim('worker-e', 120, 1182);
$repository->fail(1, 'worker-e', 'schema', 'terminal', 1183);
lifecycleAssert($repository->rows[1]['status'] === 'failed' && $repository->rows[1]['completed_at'] === 1183, 'maximum attempts must become terminal failure.');

fwrite(STDOUT, "OK: content-job lifecycle contract passed.\n");
