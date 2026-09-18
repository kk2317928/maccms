<?php

namespace app\common\util;

use InvalidArgumentException;
use think\Db;

class ContentWorkspaceDashboard
{
    private const WORKFLOW_STATES = [
        'imported', 'ai_processing', 'duplicate_review', 'tmdb_matching',
        'manual_review', 'failed', 'published', 'rejected', 'merged',
    ];

    private $clock;

    public function __construct(callable $clock = null)
    {
        $this->clock = $clock ?: 'time';
    }

    public function snapshot(array $options = []): array
    {
        $now = (int) call_user_func($this->clock);
        $budgetLimit = (int) ($options['daily_budget_micros'] ?? 0);
        $heartbeatStale = (int) ($options['heartbeat_stale_seconds'] ?? 300);
        $queueStale = (int) ($options['queue_stale_seconds'] ?? 300);
        if ($budgetLimit < 0 || $heartbeatStale < 1 || $queueStale < 1) {
            throw new InvalidArgumentException('Invalid content workspace dashboard options.');
        }

        $from = $now - 86400;
        $dayFrom = intdiv($now, 86400) * 86400;
        $workers = $this->normalizeWorkers($this->readWorkerHeartbeats(), $now, $heartbeatStale);
        return [
            'generated_at' => $now,
            'workflow' => $this->normalizeWorkflow($this->readWorkflowCounts()),
            'queue' => $this->normalizeQueue($this->readQueueSummary($now), $now, $queueStale),
            'runs' => $this->normalizeRuns($this->readRunSummary($from, $now)),
            'budget' => $this->normalizeBudget($this->readAiUsage($dayFrom, $dayFrom + 86400), $budgetLimit),
            'workers' => $workers,
            'cron' => $this->cronSummary($workers),
        ];
    }

    protected function readWorkflowCounts(): array
    {
        return Db::name('vod_ext')->field('workflow_status,COUNT(*) AS total')->group('workflow_status')->select();
    }

    protected function readQueueSummary(int $now): array
    {
        $row = Db::name('content_job')->field(
            "COALESCE(SUM(CASE WHEN status='queued' THEN 1 ELSE 0 END),0) AS queued,"
            . "COALESCE(SUM(CASE WHEN status='running' THEN 1 ELSE 0 END),0) AS running,"
            . "COALESCE(SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END),0) AS failed,"
            . "MIN(CASE WHEN status='queued' AND next_run_at<=" . $now . ' THEN next_run_at ELSE NULL END) AS oldest_ready_at'
        )->find();
        return $row ?: [];
    }

    protected function readRunSummary(int $from, int $to): array
    {
        $row = Db::name('content_job_run')->where('finished_at', '>=', $from)->where('finished_at', '<', $to)->field(
            "COALESCE(SUM(CASE WHEN status='succeeded' THEN 1 ELSE 0 END),0) AS succeeded,"
            . "COALESCE(SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END),0) AS failed,"
            . "COALESCE(SUM(CASE WHEN status='failed' AND (error_class LIKE '%rate_limit%' OR error_class='http_429') THEN 1 ELSE 0 END),0) AS rate_limited"
        )->find();
        return $row ?: [];
    }

    protected function readAiUsage(int $from, int $to): array
    {
        $row = Db::name('content_ai_run')->where('created_at', '>=', $from)->where('created_at', '<', $to)->field(
            'COALESCE(SUM(estimated_cost_micros),0) AS cost_micros,COALESCE(SUM(total_tokens),0) AS total_tokens'
        )->find();
        return $row ?: [];
    }

    protected function readWorkerHeartbeats(): array
    {
        return Db::name('content_worker_heartbeat')->order('last_seen_at desc,worker_id asc')->select();
    }

    private function normalizeWorkflow(array $rows): array
    {
        $counts = array_fill_keys(self::WORKFLOW_STATES, 0);
        foreach ($rows as $row) {
            $state = (string) ($row['workflow_status'] ?? '');
            if (array_key_exists($state, $counts)) {
                $counts[$state] = (int) ($row['total'] ?? 0);
            }
        }
        return $counts;
    }

    private function normalizeQueue(array $row, int $now, int $staleSeconds): array
    {
        $queued = (int) ($row['queued'] ?? 0);
        $running = (int) ($row['running'] ?? 0);
        $oldest = (int) ($row['oldest_ready_at'] ?? 0);
        $age = $oldest > 0 ? max(0, $now - $oldest) : 0;
        return [
            'queued' => $queued,
            'running' => $running,
            'failed' => (int) ($row['failed'] ?? 0),
            'depth' => $queued + $running,
            'oldest_age_seconds' => $age,
            'health' => $age > $staleSeconds ? 'warning' : 'healthy',
        ];
    }

    private function normalizeRuns(array $row): array
    {
        $succeeded = (int) ($row['succeeded'] ?? 0);
        $failed = (int) ($row['failed'] ?? 0);
        $total = $succeeded + $failed;
        return [
            'window_seconds' => 86400,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'total' => $total,
            'success_rate' => $total ? round($succeeded * 100 / $total, 1) : 0.0,
            'failure_rate' => $total ? round($failed * 100 / $total, 1) : 0.0,
            'rate_limit_failures' => (int) ($row['rate_limited'] ?? 0),
        ];
    }

    private function normalizeBudget(array $row, int $limit): array
    {
        $cost = (int) ($row['cost_micros'] ?? 0);
        $percent = $limit > 0 ? round($cost * 100 / $limit, 1) : null;
        return [
            'cost_micros' => $cost,
            'total_tokens' => (int) ($row['total_tokens'] ?? 0),
            'limit_micros' => $limit,
            'remaining_micros' => $limit > 0 ? max(0, $limit - $cost) : 0,
            'usage_percent' => $percent,
            'health' => $limit === 0 ? 'unlimited' : ($percent >= 100 ? 'critical' : ($percent >= 80 ? 'warning' : 'healthy')),
        ];
    }

    private function normalizeWorkers(array $rows, int $now, int $staleSeconds): array
    {
        $workers = [];
        foreach ($rows as $row) {
            $lastSeen = (int) ($row['last_seen_at'] ?? 0);
            $age = $lastSeen > 0 ? max(0, $now - $lastSeen) : $now;
            $workers[] = [
                'worker_id' => (string) ($row['worker_id'] ?? ''),
                'status' => (string) ($row['status'] ?? 'stopped'),
                'processed' => (int) ($row['processed'] ?? 0),
                'last_seen_at' => $lastSeen,
                'age_seconds' => $age,
                'health' => $lastSeen > 0 && $age <= $staleSeconds ? 'healthy' : 'stale',
            ];
        }
        return $workers;
    }

    private function cronSummary(array $workers): array
    {
        $healthy = 0;
        foreach ($workers as $worker) {
            if ($worker['health'] === 'healthy') {
                $healthy++;
            }
        }
        $stale = count($workers) - $healthy;
        $health = !$workers ? 'missing' : ($stale === 0 ? 'healthy' : ($healthy === 0 ? 'critical' : 'warning'));
        return ['workers' => count($workers), 'healthy' => $healthy, 'stale' => $stale, 'health' => $health];
    }
}
