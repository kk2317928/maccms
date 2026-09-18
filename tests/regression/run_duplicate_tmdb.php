<?php

declare(strict_types=1);

$tests = ['duplicate_candidate_scoring.php', 'duplicate_candidate_decisions.php', 'duplicate_merge.php', 'canonical_public_id.php', 'duplicate_restore.php'];
foreach ($tests as $test) {
    fwrite(STDOUT, "RUN: {$test}\n");
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $test), $exitCode);
    if ($exitCode !== 0) { exit($exitCode); }
}
fwrite(STDOUT, "PASS: duplicate/TMDB regression suite completed.\n");
