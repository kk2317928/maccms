<?php

declare(strict_types=1);

// Reuse the complete native admin/collection scenario, then assert the canonical
// production worker aliases on the same bootstrapped MySQL application.
require __DIR__ . '/native_vod_paths.php';

$tmdb = new \app\common\util\TmdbReviewJobHandler();
$handlers = \app\command\MaccmsJobs::handlerMap(
    static function (array $payload): array { return $payload; },
    $tmdb
);
foreach (['ai_normalize', 'tmdb_review', 'tmdb_manual_match', 'ai.normalize', 'tmdb_match'] as $jobType) {
    if (!isset($handlers[$jobType]) || !is_callable($handlers[$jobType])) {
        fwrite(STDERR, 'FAIL: production handler alias is missing: ' . $jobType . PHP_EOL);
        exit(1);
    }
}

fwrite(STDOUT, "OK: automatic workflow dispatch and production aliases passed.\n");
