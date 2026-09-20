<?php

namespace app\common\util;

use InvalidArgumentException;
use RuntimeException;
use think\Db;

class ContentJobAdminService
{
    private $audit;
    private $enqueue;
    private $jobPage;
    private $runPage;
    private $retryFailed;
    private $transaction;
    private $clock;
    private $transition;

    public function __construct(
        ContentAdminAudit $audit = null,
        callable $enqueue = null,
        callable $jobPage = null,
        callable $runPage = null,
        callable $retryFailed = null,
        callable $transaction = null,
        callable $clock = null,
        callable $transition = null
    ) {
        $this->audit = $audit ?: new ContentAdminAudit();
        if ($enqueue !== null) {
            $this->enqueue = $enqueue;
        } else {
            $repository = new ContentJobRepository();
            $this->enqueue = static fn (string $type, array $payload, string $key): array => $repository->enqueue($type, $payload, $key);
        }
        $this->jobPage = $jobPage ?: static function (string $type, string $status, int $offset, int $limit): array {
            $query = Db::name('content_job');
            if ($type !== '') { $query->where('job_type', $type); }
            if ($status !== '') { $query->where('status', $status); }
            $total = (int) (clone $query)->count();
            $rows = $query->order('created_at desc,job_id desc')->limit($offset, $limit)->select();
            return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
        };
        $this->runPage = $runPage ?: static function (int $jobId, int $limit): array {
            $rows = Db::name('content_job_run')->where('job_id', $jobId)->order('run_id desc')->limit($limit)->select();
            return is_array($rows) ? $rows : [];
        };
        $this->retryFailed = $retryFailed ?: function (int $jobId, callable $authorize): array {
            $row = Db::name('content_job')->where('job_id', $jobId)->lock(true)->find();
            if (!$row) { throw new RuntimeException('Content job was not found.'); }
            $authorize($row);
            if ((string) $row['status'] !== 'failed') { throw new RuntimeException('Only terminal failed jobs can be retried.'); }
            $now = (int) call_user_func($this->clock);
            $attempt = max(0, (int) ($row['attempt'] ?? 0));
            $maxAttempts = max($attempt, (int) ($row['max_attempts'] ?? 0)) + 1;
            $update = [
                'status' => 'queued', 'attempt' => $attempt, 'max_attempts' => $maxAttempts, 'next_run_at' => $now,
                'lock_owner' => '', 'lock_expires_at' => 0, 'error_class' => '',
                'error_summary' => '', 'completed_at' => 0, 'updated_at' => $now,
            ];
            $changed = Db::name('content_job')->where('job_id', $jobId)->where('status', 'failed')->update($update);
            if ($changed !== 1) { throw new RuntimeException('Content job retry conflicted with another update.'); }
            return array_merge($row, $update);
        };
        $this->transaction = $transaction ?: static fn (callable $callback) => Db::transaction($callback);
        $this->clock = $clock ?: 'time';
        $this->transition = $transition ?: function (int $jobId, string $action, string $reason, int $now, callable $authorize): array {
            $before = Db::name('content_job')->where('job_id', $jobId)->lock(true)->find();
            if (!$before) { throw new RuntimeException('Content job was not found.'); }
            $authorize($before);
            $states = ['pause' => ['queued','paused'], 'resume' => ['paused','queued'], 'skip' => ['queued','skipped']];
            if (!isset($states[$action]) || (string) $before['status'] !== $states[$action][0]) { throw new RuntimeException('Content job state does not allow this action.'); }
            if ($action === 'skip' && trim($reason) === '') { throw new InvalidArgumentException('Skip reason is required.'); }
            $update = ['status' => $states[$action][1], 'updated_at' => $now];
            if ($action === 'skip') { $update += ['error_class' => 'admin_skipped', 'error_summary' => trim($reason), 'completed_at' => $now]; }
            if (Db::name('content_job')->where(['job_id'=>$jobId,'status'=>$states[$action][0]])->update($update) !== 1) { throw new RuntimeException('Content job control conflicted.'); }
            return [$before, array_merge($before, $update)];
        };
    }

    public function enqueueBatch(string $requestedType, array $vodIds, int $actorId, string $actorName, array $grants, bool $confirmed): array
    {
        [$jobType, $permission] = $this->typeContract($requestedType);
        $this->authorize($permission, $grants, $confirmed);
        $ids = [];
        foreach ($vodIds as $vodId) {
            if (is_string($vodId) && !preg_match('/^\d+$/', trim($vodId))) { throw new InvalidArgumentException('Video IDs must be positive integers.'); }
            $id = (int) $vodId;
            if ($id <= 0) { throw new InvalidArgumentException('Video IDs must be positive integers.'); }
            $ids[$id] = $id;
        }
        ksort($ids, SORT_NUMERIC);
        $ids = array_values($ids);
        if ($ids === []) { throw new InvalidArgumentException('At least one explicit video ID is required.'); }
        if (count($ids) > 100) { throw new InvalidArgumentException('A batch may contain at most 100 video IDs.'); }

        return call_user_func($this->transaction, function () use ($requestedType, $jobType, $ids, $actorId, $actorName): array {
            $jobs = [];
            foreach ($ids as $vodId) {
                $key = 'admin-batch:' . strtolower(trim($requestedType)) . ':vod:' . $vodId;
                $jobs[] = call_user_func($this->enqueue, $jobType, ['vod_id' => $vodId], $key);
            }
            $this->audit->append(
                $actorId, $actorName, 'content.job.batch_enqueued', 'content_job_batch',
                hash('sha256', strtolower(trim($requestedType)) . ':' . implode(',', $ids)),
                [], ['job_type' => $jobType, 'requested' => count($ids)],
                ['vod_ids' => $ids]
            );
            return ['job_type' => $jobType, 'requested' => count($ids), 'jobs' => $jobs];
        });
    }

    public function page(string $requestedType = '', string $status = '', int $page = 1, int $pageSize = 20): array
    {
        $type = $requestedType === '' ? '' : $this->typeContract($requestedType)[0];
        $status = strtolower(trim($status));
        if ($status !== '' && !in_array($status, ['queued', 'paused', 'running', 'succeeded', 'failed', 'skipped'], true)) {
            throw new InvalidArgumentException('Unsupported job status filter.');
        }
        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));
        $result = (array) call_user_func($this->jobPage, $type, $status, ($page - 1) * $pageSize, $pageSize);
        $rows = $this->redactRows((array) ($result['rows'] ?? []));
        $total = max(0, (int) ($result['total'] ?? 0));
        return [
            'rows' => $rows, 'total' => $total, 'page' => $page, 'page_size' => $pageSize,
            'pages' => max(1, (int) ceil($total / $pageSize)), 'job_type' => $requestedType, 'status' => $status,
        ];
    }

    public function runs(int $jobId, int $limit = 10): array
    {
        if ($jobId <= 0) { return []; }
        return $this->redactRows((array) call_user_func($this->runPage, $jobId, max(1, min(50, $limit))));
    }

    public function retry(int $jobId, int $actorId, string $actorName, array $grants, bool $confirmed): array
    {
        if ($jobId <= 0) { throw new InvalidArgumentException('Job ID must be positive.'); }
        return call_user_func($this->transaction, function () use ($jobId, $actorId, $actorName, $grants, $confirmed): array {
            $before = [];
            $job = call_user_func($this->retryFailed, $jobId, function (array $locked) use (&$before, $grants, $confirmed): void {
                $before = $locked;
                $permission = $this->permissionForStoredType((string) ($locked['job_type'] ?? ''));
                $this->authorize($permission, $grants, $confirmed);
            });
            if ($before === []) {
                $permission = $this->permissionForStoredType((string) ($job['job_type'] ?? ''));
                $this->authorize($permission, $grants, $confirmed);
                $before = $job;
            }
            $this->audit->append(
                $actorId, $actorName, 'content.job.retried', 'content_job', (string) $jobId,
                ['status' => 'failed', 'attempt' => (int) ($before['attempt'] ?? 0)],
                ['status' => 'queued', 'attempt' => (int) ($job['attempt'] ?? 0), 'max_attempts' => (int) ($job['max_attempts'] ?? 0)], ['job_type' => (string) ($job['job_type'] ?? '')]
            );
            return $job;
        });
    }

    public function control(int $jobId, string $action, string $reason, int $actorId, string $actorName, array $grants, bool $confirmed): array
    {
        if ($jobId <= 0) { throw new InvalidArgumentException('Job ID must be positive.'); }
        $action = strtolower(trim($action));
        if (!in_array($action, ['pause','resume','skip'], true)) { throw new InvalidArgumentException('Unsupported job control action.'); }
        return call_user_func($this->transaction, function () use ($jobId,$action,$reason,$actorId,$actorName,$grants,$confirmed) {
            [$before,$after] = call_user_func($this->transition,$jobId,$action,$reason,(int) call_user_func($this->clock),function(array $job) use ($grants,$confirmed) {
                $this->authorize($this->permissionForStoredType((string) ($job['job_type'] ?? '')),$grants,$confirmed);
            });
            $this->audit->append($actorId,$actorName,'content.job.'.($action==='skip'?'skipped':$action.'d'),'content_job',(string)$jobId,['status'=>$before['status']],['status'=>$after['status']],['reason'=>$action==='skip'?$this->redactSummary($reason):'']);
            return $after;
        });
    }

    private function typeContract(string $requestedType): array
    {
        $requestedType = strtolower(trim($requestedType));
        if ($requestedType === 'ai.normalize') { return ['ai.normalize', 'run_ai']; }
        if ($requestedType === 'tmdb.match') { return ['tmdb_match', 'run_tmdb']; }
        throw new InvalidArgumentException('Unsupported batch job type.');
    }

    private function permissionForStoredType(string $jobType): string
    {
        if ($jobType === 'ai.normalize') { return 'run_ai'; }
        if ($jobType === 'tmdb_match' || $jobType === 'tmdb_manual_match') { return 'run_tmdb'; }
        throw new RuntimeException('This job type cannot be retried from the content workspace.');
    }

    private function authorize(string $permission, array $grants, bool $confirmed): void
    {
        (new ContentAdminPolicy())->assertAllowed($permission, $grants);
        if (!$confirmed) { throw new RuntimeException('Explicit confirmation is required.'); }
    }

    private function redactRows(array $rows): array
    {
        foreach ($rows as &$row) {
            if (!is_array($row)) { continue; }
            foreach (['error_summary', 'error_message'] as $field) {
                if (isset($row[$field])) { $row[$field] = $this->redactSummary((string) $row[$field]); }
            }
        }
        unset($row);
        return $rows;
    }

    private function redactSummary(string $summary): string
    {
        $summary = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $summary) ?? '';
        $summary = preg_replace('/\b(password|secret|token|api[_-]?key|private[_-]?key)\s*[:=]\s*[^\s,;]+/iu', '$1=[redacted]', $summary) ?? '';
        return function_exists('mb_substr') ? mb_substr(trim($summary), 0, 240, 'UTF-8') : substr(trim($summary), 0, 240);
    }
}
