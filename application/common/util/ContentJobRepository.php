<?php

namespace app\common\util;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use think\Db;
use Throwable;

class ContentJobRepository
{
    private $clock;

    public function __construct(callable $clock = null)
    {
        $this->clock = $clock ?: 'time';
    }

    public function enqueue(
        string $jobType,
        array $payload,
        string $idempotencyKey,
        int $priority = 0,
        int $maxAttempts = 3,
        int $nextRunAt = 0
    ): array {
        $jobType = trim($jobType);
        $idempotencyKey = trim($idempotencyKey);
        $this->assertJobType($jobType);
        $this->assertIdempotencyKey($idempotencyKey);
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('Maximum attempts must be positive.');
        }
        if ($nextRunAt < 0) {
            throw new InvalidArgumentException('Next-run time cannot be negative.');
        }

        $existing = $this->lookupByKey($jobType, $idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        try {
            $payloadJson = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Job payload must be valid JSON data.', 0, $exception);
        }

        $now = (int) call_user_func($this->clock);
        $row = [
            'job_type' => $jobType,
            'payload_json' => $payloadJson,
            'status' => 'queued',
            'priority' => $priority,
            'attempt' => 0,
            'max_attempts' => $maxAttempts,
            'next_run_at' => $nextRunAt ?: $now,
            'lock_owner' => '',
            'lock_expires_at' => 0,
            'idempotency_key' => $idempotencyKey,
            'error_class' => '',
            'error_summary' => '',
            'completed_at' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        try {
            $jobId = $this->insertJob($row);
        } catch (Throwable $exception) {
            $existing = $this->lookupByKey($jobType, $idempotencyKey);
            if ($existing !== null) {
                return $existing;
            }
            throw $exception;
        }

        $created = $this->lookupById($jobId);
        if ($created === null) {
            throw new RuntimeException('Created content job could not be read back.');
        }
        return $created;
    }

    public function find(int $jobId): ?array
    {
        if ($jobId <= 0) {
            throw new InvalidArgumentException('Job ID must be positive.');
        }
        return $this->lookupById($jobId);
    }

    public function claim(string $workerId, int $leaseSeconds, int $now = 0): ?array
    {
        $this->assertWorkerId($workerId);
        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('Lease duration must be between 1 and 3600 seconds.');
        }
        $now = $now ?: (int) call_user_func($this->clock);
        $jobId = $this->claimOne($workerId, $now, $now + $leaseSeconds);
        if ($jobId === null) {
            return null;
        }
        $job = $this->lookupById($jobId);
        if ($job === null) {
            throw new RuntimeException('Claimed content job could not be read back.');
        }
        $this->recordClaim($job, $workerId, $now);
        return $job;
    }

    public function complete(int $jobId, string $workerId, array $metrics = [], int $now = 0): bool
    {
        $this->assertJobId($jobId);
        $this->assertWorkerId($workerId);
        $now = $now ?: (int) call_user_func($this->clock);
        try {
            $metricsJson = json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Run metrics must be valid JSON data.', 0, $exception);
        }
        return $this->completeOne($jobId, $workerId, $now, $metricsJson);
    }

    public function fail(
        int $jobId,
        string $workerId,
        string $errorClass,
        string $summary,
        int $now = 0
    ): bool {
        $this->assertJobId($jobId);
        $this->assertWorkerId($workerId);
        if (!preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/', $errorClass)) {
            throw new InvalidArgumentException('Invalid job error class.');
        }
        $summary = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $summary));
        if (strlen($summary) > 1000) {
            $summary = substr($summary, 0, 1000);
        }
        $now = $now ?: (int) call_user_func($this->clock);
        return $this->failOne($jobId, $workerId, $now, $errorClass, $summary);
    }

    protected function lookupById(int $jobId): ?array
    {
        $row = Db::name('content_job')->where('job_id', $jobId)->find();
        return $row ?: null;
    }

    protected function lookupByKey(string $jobType, string $idempotencyKey): ?array
    {
        $row = Db::name('content_job')
            ->where('job_type', $jobType)
            ->where('idempotency_key', $idempotencyKey)
            ->find();
        return $row ?: null;
    }

    protected function insertJob(array $row): int
    {
        return (int) Db::name('content_job')->insertGetId($row);
    }

    protected function claimOne(string $workerId, int $now, int $leaseExpiresAt): ?int
    {
        $table = $this->tableName('content_job');
        Db::query('SELECT LAST_INSERT_ID(0) AS job_id');
        $affected = Db::execute(
            'UPDATE `' . $table . '` SET '
            . '`job_id`=LAST_INSERT_ID(`job_id`),`status`=?,`attempt`=`attempt`+1,'
            . '`lock_owner`=?,`lock_expires_at`=?,`updated_at`=? '
            . 'WHERE ((`status`=? AND `next_run_at`<=?) OR (`status`=? AND `lock_expires_at`<=?)) '
            . 'AND `attempt`<`max_attempts` '
            . 'ORDER BY `priority` DESC,`next_run_at` ASC,`job_id` ASC LIMIT 1',
            ['running', $workerId, $leaseExpiresAt, $now, 'queued', $now, 'running', $now]
        );
        if ($affected < 1) {
            return null;
        }
        $rows = Db::query('SELECT LAST_INSERT_ID() AS job_id');
        $jobId = (int) ($rows[0]['job_id'] ?? 0);
        return $jobId > 0 ? $jobId : null;
    }

    protected function recordClaim(array $job, string $workerId, int $now): void
    {
        Db::name('content_job_run')
            ->where('job_id', (int) $job['job_id'])
            ->where('status', 'running')
            ->where('attempt', '<', (int) $job['attempt'])
            ->update([
                'status' => 'failed', 'finished_at' => $now,
                'error_class' => 'lease_expired', 'error_summary' => 'Worker lease expired before completion.',
            ]);
        Db::name('content_job_run')->insert([
            'job_id' => (int) $job['job_id'], 'attempt' => (int) $job['attempt'],
            'status' => 'running', 'worker_id' => $workerId,
            'started_at' => $now, 'finished_at' => 0,
            'error_class' => '', 'error_summary' => '', 'metrics_json' => null,
        ]);
    }

    protected function completeOne(int $jobId, string $workerId, int $now, string $metricsJson): bool
    {
        $affected = Db::name('content_job')->where([
            'job_id' => $jobId, 'status' => 'running', 'lock_owner' => $workerId,
        ])->update([
            'status' => 'succeeded', 'lock_owner' => '', 'lock_expires_at' => 0,
            'error_class' => '', 'error_summary' => '', 'completed_at' => $now, 'updated_at' => $now,
        ]);
        if ($affected < 1) {
            return false;
        }
        Db::name('content_job_run')->where([
            'job_id' => $jobId, 'status' => 'running', 'worker_id' => $workerId,
        ])->order('attempt desc')->limit(1)->update([
            'status' => 'succeeded', 'finished_at' => $now, 'metrics_json' => $metricsJson,
        ]);
        return true;
    }

    protected function failOne(int $jobId, string $workerId, int $now, string $errorClass, string $summary): bool
    {
        $job = $this->lookupById($jobId);
        if ($job === null || $job['status'] !== 'running' || $job['lock_owner'] !== $workerId) {
            return false;
        }
        $terminal = (int) $job['attempt'] >= (int) $job['max_attempts'];
        $delay = min(3600, 60 * (2 ** max(0, (int) $job['attempt'] - 1)));
        $affected = Db::name('content_job')->where([
            'job_id' => $jobId, 'status' => 'running', 'lock_owner' => $workerId,
        ])->update([
            'status' => $terminal ? 'failed' : 'queued',
            'next_run_at' => $terminal ? (int) $job['next_run_at'] : $now + $delay,
            'lock_owner' => '', 'lock_expires_at' => 0,
            'error_class' => $errorClass, 'error_summary' => $summary,
            'completed_at' => $terminal ? $now : 0, 'updated_at' => $now,
        ]);
        if ($affected < 1) {
            return false;
        }
        Db::name('content_job_run')->where([
            'job_id' => $jobId, 'status' => 'running', 'worker_id' => $workerId,
        ])->order('attempt desc')->limit(1)->update([
            'status' => 'failed', 'finished_at' => $now,
            'error_class' => $errorClass, 'error_summary' => $summary,
        ]);
        return true;
    }

    private function assertJobType(string $jobType): void
    {
        if (!preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/', $jobType)) {
            throw new InvalidArgumentException('Invalid content job type.');
        }
    }

    private function assertIdempotencyKey(string $idempotencyKey): void
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 191 || preg_match('/[\x00-\x1F\x7F]/', $idempotencyKey)) {
            throw new InvalidArgumentException('Invalid content job idempotency key.');
        }
    }

    private function assertJobId(int $jobId): void
    {
        if ($jobId <= 0) {
            throw new InvalidArgumentException('Job ID must be positive.');
        }
    }

    private function assertWorkerId(string $workerId): void
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/', $workerId)) {
            throw new InvalidArgumentException('Invalid worker ID.');
        }
    }

    private function tableName(string $name): string
    {
        $prefix = (string) config('database.prefix');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
            throw new RuntimeException('Invalid database table prefix.');
        }
        return $prefix . $name;
    }
}
