<?php

declare(strict_types=1);

$tests = [
    'content_workflow_coordinator.php',
    'content_workflow_admin.php',
    'taxonomy_suggestions.php',
    'content_job_contract.php',
    'content_job_lifecycle.php',
    'content_job_worker.php',
    'hardened_http.php',
    'ai_normalization_validator.php',
    'ai_run_repository.php',
    'field_governance.php',
    'ai_normalization_pipeline.php',
    'installed_ai_duplicate_workflow.php',
    'ai_usage_and_duplicate_config.php',
];

foreach ($tests as $test) {
    $path = __DIR__ . '/' . $test;
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL: missing AI jobs regression script {$test}\n");
        exit(1);
    }

    fwrite(STDOUT, "RUN: {$test}\n");
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path), $exitCode);
    if ($exitCode !== 0) {
        fwrite(STDERR, "FAIL: {$test} exited with status {$exitCode}\n");
        exit($exitCode);
    }
}

fwrite(STDOUT, "PASS: AI jobs regression suite completed.\n");
