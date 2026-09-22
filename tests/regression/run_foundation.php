<?php

declare(strict_types=1);

$tests = [
    'migration_cli_contract.php',
    'migration_mysql_ddl.php',
    'migration_discovery.php',
    'migration_contract.php',
    'public_id_generator.php',
    'vod_ext_public_id.php',
    'vod_workflow.php',
    'vod_playback_codec.php',
    'vod_extension_contract.php',
    'vod_extension_hook.php',
    'content_workflow_hooks.php',
    'content_repair_command.php',
    'vod_repeat_repair.php',
    'public_id_mixed_case.php',
    'taxonomy_dictionary.php',
];

foreach ($tests as $test) {
    $path = __DIR__ . '/' . $test;
    if (!is_file($path)) {
        fwrite(STDERR, "FAIL: missing foundation regression script {$test}\n");
        exit(1);
    }

    fwrite(STDOUT, "RUN: {$test}\n");
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path), $exitCode);
    if ($exitCode !== 0) {
        fwrite(STDERR, "FAIL: {$test} exited with status {$exitCode}\n");
        exit($exitCode);
    }
}

fwrite(STDOUT, "PASS: foundation regression suite completed.\n");
