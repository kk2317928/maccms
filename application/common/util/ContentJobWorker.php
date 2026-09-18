<?php

namespace app\common\util;

use InvalidArgumentException;
use JsonException;
use Throwable;

class ContentJobWorker
{
    private $repository;
    private $handlers;
    private $clock;

    public function __construct($repository, array $handlers, callable $clock = null)
    {
        $this->repository = $repository;
        $this->handlers = $handlers;
        $this->clock = $clock ?: 'time';
    }

    public function run(string $workerId, int $maxJobs, int $maxSeconds, int $leaseSeconds = 120): array
    {
        if ($maxJobs < 1 || $maxJobs > 1000 || $maxSeconds < 1 || $maxSeconds > 3600) {
            throw new InvalidArgumentException('Worker budgets are outside supported bounds.');
        }
        $startedAt = $this->now();
        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $this->repository->heartbeat($workerId, 'running', 0, $startedAt);

        while ($processed < $maxJobs && $this->now() - $startedAt < $maxSeconds) {
            $job = $this->repository->claim($workerId, $leaseSeconds, $this->now());
            if ($job === null) {
                break;
            }
            $processed++;
            $type = (string) $job['job_type'];
            try {
                if (!isset($this->handlers[$type]) || !is_callable($this->handlers[$type])) {
                    throw new InvalidArgumentException('No handler is registered for this job type.');
                }
                $payload = json_decode((string) $job['payload_json'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new JsonException('Job payload is not an object or array.');
                }
                $metrics = call_user_func($this->handlers[$type], $payload, $job);
                if (!is_array($metrics)) {
                    $metrics = [];
                }
                if ($this->repository->complete((int) $job['job_id'], $workerId, $metrics, $this->now())) {
                    $succeeded++;
                } else {
                    $failed++;
                }
            } catch (Throwable $exception) {
                $class = $exception instanceof JsonException ? 'payload_invalid' : 'handler_error';
                $this->repository->fail(
                    (int) $job['job_id'], $workerId, $class,
                    $class === 'payload_invalid' ? 'Job payload is invalid.' : 'Job handler failed.',
                    $this->now()
                );
                $failed++;
            }
            $this->repository->heartbeat($workerId, 'running', $processed, $this->now());
        }

        $this->repository->heartbeat($workerId, 'idle', $processed, $this->now());
        return ['processed' => $processed, 'succeeded' => $succeeded, 'failed' => $failed];
    }

    private function now(): int
    {
        return (int) call_user_func($this->clock);
    }
}
