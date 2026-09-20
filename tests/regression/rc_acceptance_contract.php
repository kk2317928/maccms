<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workflowPath = $root . '/.github/workflows/rc-rollback-rehearsal.yml';
$reportPath = $root . '/docs/release/headless-ai-v1-rc-acceptance.md';
foreach ([$workflowPath, $reportPath] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL: missing RC artifact: {$path}\n");
        exit(1);
    }
}
$workflow = (string) file_get_contents($workflowPath);
foreach ([
    'mysqldump --single-transaction',
    'maccms_ci_restore',
    'sha256sum',
    'git archive',
    'ln -sfn',
    'php think list',
    "MACCMS_TEST_DATABASE=maccms_ci_restore",
    'mysql_foundation.php',
    'native_vod_paths.php',
    'outbound_inventory.php --enforce',
] as $needle) {
    if (strpos($workflow, $needle) === false) {
        fwrite(STDERR, "FAIL: rollback workflow missing: {$needle}\n");
        exit(1);
    }
}
$report = (string) file_get_contents($reportPath);
foreach ([
    'Status: ACCEPTED',
    'Candidate SHA:',
    'PHP regression',
    'MySQL 5.7',
    'MySQL 8.0',
    'Native video',
    'Prohibited outbound',
    'Database restore',
    'Application rollback',
    'Known limitations',
    'T-097',
] as $needle) {
    if (strpos($report, $needle) === false) {
        fwrite(STDERR, "FAIL: RC report missing: {$needle}\n");
        exit(1);
    }
}
if (preg_match('/\b(?:PENDING|NOT_RUN|UNVERIFIED)\b/', $report)) {
    fwrite(STDERR, "FAIL: RC report retains unresolved acceptance state.\n");
    exit(1);
}
fwrite(STDOUT, "OK: RC acceptance and rollback evidence contract passed.\n");
