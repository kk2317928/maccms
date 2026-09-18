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
}
