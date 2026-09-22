<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$servicePath = $root . '/application/common/util/ExternalImageIngestionService.php';
$handlerPath = $root . '/application/common/util/TmdbPosterJobHandler.php';
if (!is_file($servicePath)) { fwrite(STDERR, "FAIL: ExternalImageIngestionService is missing.\n"); exit(1); }
if (!is_file($handlerPath)) { fwrite(STDERR, "FAIL: TmdbPosterJobHandler is missing.\n"); exit(1); }

$service = (string) file_get_contents($servicePath);
$handler = (string) file_get_contents($handlerPath);
$s3 = (string) file_get_contents($root . '/application/common/extend/upload/S3.php');
$jobs = (string) file_get_contents($root . '/application/command/MaccmsJobs.php');

foreach (['HardenedHttpClient', 'getimagesizefromstring', 'sha256', 'random_bytes', 'max_redirects', 'stored_url', 'local_path', 'mime'] as $needle) {
    if (strpos($service, $needle) === false) { fwrite(STDERR, "FAIL: image ingestion missing {$needle}.\n"); exit(1); }
}
foreach (['vod_pic', 'old_poster_s3', 'poster_s3', 'FieldGovernance', 'tmdb_poster_ingest'] as $needle) {
    if (strpos($handler, $needle) === false && strpos($jobs, $needle) === false) { fwrite(STDERR, "FAIL: poster workflow missing {$needle}.\n"); exit(1); }
}
if (strpos($s3, 'return $file_path;') !== false && strpos($s3, 'RuntimeException') === false) {
    fwrite(STDERR, "FAIL: S3 driver still silently treats local path as upload success.\n"); exit(1);
}

fwrite(STDOUT, "PASS: hardened TMDB poster ingestion source contract.\n");
