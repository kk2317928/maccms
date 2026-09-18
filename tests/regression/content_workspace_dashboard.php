<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/application/common/util/ContentWorkspaceDashboard.php';
if (!is_file($path)) {
    fwrite(STDERR, "FAIL: ContentWorkspaceDashboard is missing.\n");
    exit(1);
}
require_once $path;
$providerPath = $root . '/application/common/util/AiProvider.php';
require_once $providerPath;

use app\common\util\ContentWorkspaceDashboard;
use app\common\util\AiProvider;

function dashboardAssert($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

final class FixtureContentWorkspaceDashboard extends ContentWorkspaceDashboard
{
    public $workflowRows = [];
    public $queueRow = [];
    public $runRow = [];
    public $usageRow = [];
    public $workerRows = [];

    protected function readWorkflowCounts(): array { return $this->workflowRows; }
    protected function readQueueSummary(int $now): array { return $this->queueRow; }
    protected function readRunSummary(int $from, int $to): array { return $this->runRow; }
    protected function readAiUsage(int $from, int $to): array { return $this->usageRow; }
    protected function readWorkerHeartbeats(): array { return $this->workerRows; }
}

$dashboard = new FixtureContentWorkspaceDashboard(static fn (): int => 1000);
$dashboard->workflowRows = [
    ['workflow_status' => 'imported', 'total' => '4'],
    ['workflow_status' => 'manual_review', 'total' => '3'],
    ['workflow_status' => 'published', 'total' => '9'],
];
$dashboard->queueRow = ['queued' => '5', 'running' => '2', 'failed' => '1', 'oldest_runnable_at' => '850'];
$dashboard->runRow = ['succeeded' => '8', 'failed' => '2', 'rate_limited' => '1'];
$dashboard->usageRow = ['cost_micros' => '800', 'total_tokens' => '1200'];
$dashboard->workerRows = [
    ['worker_id' => 'cron-a', 'status' => 'idle', 'processed' => '7', 'last_seen_at' => '950'],
    ['worker_id' => 'cron-b', 'status' => 'running', 'processed' => '2', 'last_seen_at' => '600'],
    ['worker_id' => 'cron-retired', 'status' => 'stopped', 'processed' => '9', 'last_seen_at' => '990'],
];

$snapshot = $dashboard->snapshot([
    'daily_budget_micros' => 1000,
    'heartbeat_stale_seconds' => 300,
    'queue_stale_seconds' => 120,
]);

dashboardAssert($snapshot['workflow']['imported'] === 4, 'workflow counts must be normalized to integers.');
dashboardAssert($snapshot['workflow']['tmdb_matching'] === 0, 'missing workflow states must be zero-filled.');
dashboardAssert($snapshot['workflow_rows'][0] === ['state' => 'imported', 'total' => 4], 'the view must receive numerically indexed workflow rows.');
dashboardAssert($snapshot['queue'] === [
    'queued' => 5, 'running' => 2, 'failed' => 1, 'depth' => 7,
    'oldest_age_seconds' => 150, 'health' => 'warning',
], 'queue depth, age and health are incorrect.');
dashboardAssert($snapshot['runs'] === [
    'window_seconds' => 86400, 'succeeded' => 8, 'failed' => 2, 'total' => 10,
    'success_rate' => 80.0, 'failure_rate' => 20.0, 'rate_limit_failures' => 1,
], '24-hour run rates or rate-limit count are incorrect.');
dashboardAssert($snapshot['budget'] === [
    'cost_micros' => 800, 'total_tokens' => 1200, 'limit_micros' => 1000,
    'remaining_micros' => 200, 'usage_percent' => 80.0, 'health' => 'warning',
], 'daily AI budget usage is incorrect.');
dashboardAssert($snapshot['workers'][0]['age_seconds'] === 50 && $snapshot['workers'][0]['health'] === 'healthy', 'fresh heartbeat must be healthy.');
dashboardAssert($snapshot['workers'][1]['age_seconds'] === 400 && $snapshot['workers'][1]['health'] === 'stale', 'expired heartbeat must be stale.');
dashboardAssert($snapshot['workers'][2]['health'] === 'stale', 'a stopped worker must not be reported healthy even with a fresh heartbeat.');
dashboardAssert($snapshot['cron']['healthy'] === 1 && $snapshot['cron']['stale'] === 2 && $snapshot['cron']['health'] === 'healthy', 'a fresh active worker must keep the overall Cron heartbeat healthy.');
dashboardAssert(ContentWorkspaceDashboard::optionsFromConfig([
    'ai_content' => ['daily_budget_micros' => '2500'],
    'content_workspace' => ['heartbeat_stale_seconds' => '90', 'queue_stale_seconds' => '180'],
]) === ['daily_budget_micros' => 2500, 'heartbeat_stale_seconds' => 90, 'queue_stale_seconds' => 180], 'dashboard options must use the canonical AI-content budget setting.');
dashboardAssert(AiProvider::dailyBudgetMicros(['ai_content' => ['daily_budget_micros' => '2500']]) === 2500, 'AI provider and dashboard must share one persisted daily budget.');

$empty = new FixtureContentWorkspaceDashboard(static fn (): int => 1000);
$emptySnapshot = $empty->snapshot(['daily_budget_micros' => 0]);
dashboardAssert($emptySnapshot['runs']['success_rate'] === 0.0, 'empty run windows must not divide by zero.');
dashboardAssert($emptySnapshot['budget']['usage_percent'] === null && $emptySnapshot['budget']['health'] === 'unlimited', 'zero budget must mean unlimited.');
dashboardAssert($emptySnapshot['cron']['health'] === 'missing', 'missing Cron heartbeat must be explicit.');

try {
    $empty->snapshot(['heartbeat_stale_seconds' => 0]);
} catch (InvalidArgumentException $exception) {
    fwrite(STDOUT, "OK: content workspace dashboard metrics contract passed.\n");
    exit(0);
}

fwrite(STDERR, "FAIL: invalid dashboard thresholds must be rejected.\n");
exit(1);
