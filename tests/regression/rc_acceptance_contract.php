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
    'Status: AUTOMATED ACCEPTED / MANUAL SMOKE PENDING',
    'Verified implementation SHA:',
    'PHP 8.1 regression',
    'MySQL 5.7 focused lifecycle',
    'MySQL 8.0 focused lifecycle',
    'Native admin/collection/playback',
    'API v1/OpenAPI/security/outbound',
    'Database/application rollback',
    'Known limitations and manual gate',
    'headless-ai-v1.0.3-rc1',
    'headless-ai-v1.0.3',
] as $needle) {
    if (strpos($report, $needle) === false) {
        fwrite(STDERR, "FAIL: RC report missing: {$needle}\n");
        exit(1);
    }
}

$permittedPending = [
    'Status: AUTOMATED ACCEPTED / MANUAL SMOKE PENDING',
    '| Fresh Web/browser smoke | NOT_RUN |',
];
$unresolvedReport = str_replace($permittedPending, '', $report);
if (preg_match('/\b(?:PENDING|NOT_RUN|UNVERIFIED)\b/', $unresolvedReport)) {
    fwrite(STDERR, "FAIL: RC report retains an unresolved state outside the explicit manual smoke gate.\n");
    exit(1);
}
if (strpos($report, 'Final tag: `headless-ai-v1.0.3` is blocked') === false) {
    fwrite(STDERR, "FAIL: RC report must keep the final release tag blocked.\n");
    exit(1);
}
fwrite(STDOUT, "OK: RC acceptance and rollback evidence contract passed.\n");
