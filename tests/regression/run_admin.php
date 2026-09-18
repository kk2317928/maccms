<?php

declare(strict_types=1);

$tests = ['content_admin_security.php', 'content_workspace_dashboard.php'];
foreach ($tests as $test) {
    fwrite(STDOUT, "RUN: {$test}\n");
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $test), $exitCode);
    if ($exitCode !== 0) { exit($exitCode); }
}
fwrite(STDOUT, "PASS: intelligent-content admin regression suite completed.\n");
