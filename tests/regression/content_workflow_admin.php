<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$statusPath = $root . '/application/common/util/ContentWorkflowStatus.php';
if (!is_file($statusPath)) {
    fwrite(STDERR, "FAIL: ContentWorkflowStatus service is missing\n");
    exit(1);
}
require_once $statusPath;

$vodController = (string) file_get_contents($root . '/application/admin/controller/Vod.php');
$vodForm = (string) file_get_contents($root . '/application/admin/view_new/vod/info.html');
$coordinator = (string) file_get_contents($root . '/application/common/util/ContentWorkflowCoordinator.php');
$handlers = (string) file_get_contents($root . '/application/command/MaccmsJobs.php');

foreach ([
    'ContentWorkflowStatus',
    'workflowRerun',
    "validate('Token')",
    'rerunAi',
    'rerunTmdb',
] as $needle) {
    assertWorkflowAdmin(strpos($vodController, $needle) !== false, "video controller missing {$needle}");
}
foreach ([
    '內容自動化狀態',
    '立即執行 AI／分類',
    '重新執行 TMDB 配對',
    'workflow-rerun',
    'latest_job',
    'safe_failure_class',
] as $needle) {
    assertWorkflowAdmin(strpos($vodForm, $needle) !== false, "video workflow UI missing {$needle}");
}
assertWorkflowAdmin(strpos($vodForm, 'placeholder="影片 vod_id"') === false, 'per-video workflow requires manual vod_id entry');
assertWorkflowAdmin(strpos($coordinator, "'ai_normalize'") !== false && strpos($coordinator, "'tmdb_review'") !== false, 'coordinator job names are not canonical');
foreach (["'ai_normalize'", "'tmdb_review'", "'tmdb_manual_match'", "'ai.normalize'", "'tmdb_match'"] as $needle) {
    assertWorkflowAdmin(strpos($handlers, $needle) !== false, "production worker missing {$needle}");
}

final class MemoryWorkflowStatus extends \app\common\util\ContentWorkflowStatus
{
    public $ext = [];
    public $jobs = [];
    protected function loadExtension(int $vodId): array { return $this->ext; }
    protected function loadLatestJob(int $vodId): array { return $this->jobs; }
}

$status = new MemoryWorkflowStatus();
$status->ext = [
    'public_id' => 'aB3xY9',
    'workflow_status' => 'failed',
    'ai_completed_at' => 11,
    'duplicate_checked_at' => 12,
    'tmdb_completed_at' => 0,
    'updated_at' => 13,
];
$status->jobs = [
    'job_id' => 44,
    'job_type' => 'tmdb_review',
    'status' => 'failed',
    'last_error_class' => 'provider_rate_limited',
    'updated_at' => 14,
];
$result = $status->forVideo(9);
assertWorkflowAdmin($result['public_id'] === 'aB3xY9', 'public identity case was changed');
assertWorkflowAdmin($result['latest_job']['job_id'] === 44, 'latest job is not exposed');
assertWorkflowAdmin($result['safe_failure_class'] === 'provider_rate_limited', 'safe failure class is not exposed');
assertWorkflowAdmin($result['can_rerun_ai'] === true && $result['can_rerun_tmdb'] === true, 'failed video cannot be retried');

fwrite(STDOUT, "PASS: per-video content workflow administrator contract\n");

function assertWorkflowAdmin(bool $condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
