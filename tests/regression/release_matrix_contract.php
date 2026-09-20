<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workflow = $root . '/.github/workflows/release-regression.yml';
if (!is_file($workflow)) {
    fwrite(STDERR, "FAIL: release regression workflow is missing.\n");
    exit(1);
}
$source = (string) file_get_contents($workflow);
$required = [
    "mysql:5.7",
    "mysql:8.0",
    "php tests/regression/run_baseline.php",
    "php tests/regression/run_foundation.php",
    "php tests/regression/run_ai_jobs.php",
    "php tests/regression/run_duplicate_tmdb.php",
    "php tests/regression/run_admin.php",
    "php tests/regression/run_api_v1.php",
    "php tests/integration/mysql_foundation.php",
    "php tests/integration/content_job_lifecycle.php",
    "php tests/integration/api_v1_analytics.php",
    "php tests/integration/native_vod_paths.php",
    "php tests/regression/outbound_inventory.php --enforce",
    "done, applied=0, skipped=18",
];
foreach ($required as $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "FAIL: release workflow missing: {$needle}\n");
        exit(1);
    }
}
if (preg_match('/if:\s*matrix\.mysql\.family[^\n]*\n\s*run:\s*php tests\/integration\/native_vod_paths\.php/', $source)) {
    fwrite(STDERR, "FAIL: native video paths must run for every supported MySQL family.\n");
    exit(1);
}
fwrite(STDOUT, "OK: release regression matrix contract passed.\n");
