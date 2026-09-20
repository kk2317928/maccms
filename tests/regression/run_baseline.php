<?php

declare(strict_types=1);

$suiteDir = null;
for ($index = 1; $index < $argc; $index++) {
    if ($argv[$index] !== '--suite-dir' || !isset($argv[$index + 1])) {
        fwrite(STDERR, "Usage: php run_baseline.php [--suite-dir <directory>]\n");
        exit(2);
    }

    $suiteDir = $argv[++$index];
}

if ($suiteDir !== null) {
    $resolvedSuiteDir = realpath($suiteDir);
    if ($resolvedSuiteDir === false || !is_dir($resolvedSuiteDir)) {
        fwrite(STDERR, "Regression suite directory does not exist: {$suiteDir}\n");
        exit(2);
    }

    $scripts = glob($resolvedSuiteDir . DIRECTORY_SEPARATOR . '*.php');
    if ($scripts === false) {
        fwrite(STDERR, "Unable to discover regression scripts in: {$resolvedSuiteDir}\n");
        exit(2);
    }
    sort($scripts, SORT_STRING);
} else {
    $scripts = [
        __DIR__ . '/source_inventory.php',
        __DIR__ . '/outbound_inventory.php',
        __DIR__ . '/official_update_removed.php',
        __DIR__ . '/prohibited_defaults_removed.php',
        __DIR__ . '/outbound_policy_adoption.php',
        __DIR__ . '/security_release_hardening.php',
        __DIR__ . '/release_matrix_contract.php',
        __DIR__ . '/operations_runbook_contract.php',
        __DIR__ . '/user_register_validate.php',
    ];
}

if ($scripts === []) {
    fwrite(STDERR, "No regression scripts found.\n");
    exit(2);
}

fwrite(STDOUT, 'MACCMS baseline regression runner' . PHP_EOL);
fwrite(STDOUT, 'PHP: ' . PHP_VERSION . ' (' . PHP_BINARY . ')' . PHP_EOL);

foreach ($scripts as $script) {
    $name = basename($script);
    if (!is_file($script)) {
        fwrite(STDERR, "FAIL: missing regression script {$name}\n");
        exit(2);
    }

    fwrite(STDOUT, "RUN: {$name}\n");
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script), $exitCode);
    if ($exitCode !== 0) {
        fwrite(STDERR, "FAIL: {$name} exited with status {$exitCode}\n");
        exit($exitCode);
    }

    fwrite(STDOUT, "PASS: {$name}\n");
}

fwrite(STDOUT, 'PASS: baseline regression suite completed (' . count($scripts) . ' scripts).' . PHP_EOL);
exit(0);
