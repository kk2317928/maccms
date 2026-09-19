<?php

declare(strict_types=1);

$tests = ['content_admin_security.php', 'content_workspace_dashboard.php', 'ai_field_review.php', 'duplicate_review_ui.php', 'tmdb_review_ui.php', 'final_publication_ui.php', 'batch_job_admin.php', 'admin_permission_compatibility.php'];
foreach ($tests as $test) {
    fwrite(STDOUT, "RUN: {$test}\n");
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $test), $exitCode);
    if ($exitCode !== 0) { exit($exitCode); }
}
fwrite(STDOUT, "PASS: intelligent-content admin regression suite completed.\n");
