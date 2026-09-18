<?php

declare(strict_types=1);

$tests = [
    'content_job_contract.php',
    'content_job_lifecycle.php',
    'content_job_worker.php',
    'hardened_http.php',
    'ai_normalization_validator.php',
    'ai_run_repository.php',
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
