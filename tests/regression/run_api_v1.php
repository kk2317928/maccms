<?php
declare(strict_types=1);

$tests = array(
    __DIR__ . '/api_v1_foundation.php',
    __DIR__ . '/api_v1_public_catalog.php',
    __DIR__ . '/api_v1_locale_canonical.php',
    __DIR__ . '/api_v1_sessions.php',
);

foreach ($tests as $test) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($test);
    fwrite(STDOUT, '$ ' . $command . PHP_EOL);
    passthru($command, $status);
    if ($status !== 0) {
        fwrite(STDERR, basename($test) . ' failed with status ' . $status . PHP_EOL);
        exit($status);
    }
}

fwrite(STDOUT, "API v1 regression suite passed." . PHP_EOL);
