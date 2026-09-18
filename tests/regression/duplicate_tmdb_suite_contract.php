<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$runner = (string) file_get_contents(__DIR__ . '/run_duplicate_tmdb.php');
$workflow = (string) file_get_contents($root . '/.github/workflows/php-regression.yml');
$ordered = [
    'duplicate_tmdb_suite_contract.php',
    'duplicate_candidate_scoring.php',
    'duplicate_candidate_decisions.php',
    'duplicate_merge.php',
    'canonical_public_id.php',
    'duplicate_restore.php',
    'tmdb_matching.php',
    'tmdb_import.php',
];

$offset = -1;
foreach ($ordered as $test) {
    if (!is_file(__DIR__ . '/' . $test)) { fwrite(STDERR, "FAIL: suite member {$test} is missing.\n"); exit(1); }
    $position = strpos($runner, "'{$test}'");
    if ($position === false || $position <= $offset) { fwrite(STDERR, "FAIL: suite member {$test} is not in deterministic lifecycle order.\n"); exit(1); }
    $offset = $position;
}
if (strpos($runner, 'if ($exitCode !== 0) { exit($exitCode); }') === false) { fwrite(STDERR, "FAIL: duplicate/TMDB runner must stop on the first failure.\n"); exit(1); }
if (strpos($workflow, 'php tests/regression/run_duplicate_tmdb.php') === false) { fwrite(STDERR, "FAIL: CI does not execute the duplicate/TMDB suite.\n"); exit(1); }

$guards = [
    'application/common/util/DuplicateMergeService.php' => ["decision'] ?? '') !== 'pending'", 'reviewerId'],
    'application/common/util/DuplicateRestoreService.php' => ['content_duplicate_restore', 'Restoration conflict'],
    'application/common/model/VodExt.php' => ['canonical_public_id', 'is_alias'],
    'application/common/util/TmdbMatchService.php' => ["'requires_review' => true", "'status' => 'no_match'"],
    'application/common/util/TmdbImportService.php' => ['approvedFields', "'tmdb'"],
];
foreach ($guards as $relative => $needles) {
    $source = (string) file_get_contents($root . '/' . $relative);
    foreach ($needles as $needle) {
        if (strpos($source, $needle) === false) { fwrite(STDERR, "FAIL: {$relative} is missing safety guard {$needle}.\n"); exit(1); }
    }
}

fwrite(STDOUT, "OK: fail-closed duplicate/TMDB lifecycle suite contract passed.\n");
