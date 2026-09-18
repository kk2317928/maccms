<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workflowPath = $root . '/application/common/util/VodWorkflow.php';
if (!is_file($workflowPath)) {
    fwrite(STDERR, "FAIL: VodWorkflow is missing.\n");
    exit(1);
}
require $workflowPath;

use app\common\util\VodWorkflow;

function workflow_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$states = [
    'imported',
    'ai_processing',
    'duplicate_review',
    'tmdb_matching',
    'manual_review',
    'published',
    'rejected',
    'failed',
    'merged',
];

$allowed = [
    ['imported', 'ai_processing'],
    ['ai_processing', 'duplicate_review'],
    ['duplicate_review', 'tmdb_matching'],
    ['duplicate_review', 'merged'],
    ['tmdb_matching', 'manual_review'],
    ['manual_review', 'published'],
    ['manual_review', 'rejected'],
    ['ai_processing', 'failed'],
    ['tmdb_matching', 'failed'],
    ['failed', 'imported'],
];
$allowedLookup = [];
foreach ($allowed as $edge) {
    $allowedLookup[$edge[0] . '>' . $edge[1]] = true;
}

foreach ($states as $from) {
    foreach ($states as $to) {
        $key = $from . '>' . $to;
        $expected = isset($allowedLookup[$key]);
        workflow_assert(
            VodWorkflow::canTransition($from, $to) === $expected,
            "unexpected canTransition result for {$key}."
        );

        try {
            VodWorkflow::assertTransition($from, $to);
            workflow_assert($expected, "forbidden transition {$key} was accepted.");
        } catch (DomainException $exception) {
            workflow_assert(!$expected, "allowed transition {$key} was rejected.");
        }
    }
}

foreach ([['unknown', 'imported'], ['imported', 'unknown']] as $edge) {
    foreach (['canTransition', 'assertTransition'] as $method) {
        $invalidRejected = false;
        try {
            VodWorkflow::$method($edge[0], $edge[1]);
        } catch (InvalidArgumentException $exception) {
            $invalidRejected = true;
        }
        workflow_assert($invalidRejected, "{$method} must reject unknown workflow states.");
    }
}

workflow_assert(VodWorkflow::nextAfterRetry('ai_processing') === 'imported', 'AI retry must restart at imported.');
workflow_assert(VodWorkflow::nextAfterRetry('tmdb_matching') === 'imported', 'TMDB retry must restart at imported.');
foreach (array_merge($states, ['unknown']) as $failedStage) {
    if ($failedStage === 'ai_processing' || $failedStage === 'tmdb_matching') {
        continue;
    }
    $retryRejected = false;
    try {
        VodWorkflow::nextAfterRetry($failedStage);
    } catch (InvalidArgumentException $exception) {
        $retryRejected = true;
    }
    workflow_assert($retryRejected, "unsupported retry stage {$failedStage} must be rejected.");
}

$source = file_get_contents($workflowPath);
workflow_assert(strpos($source, 'vod_status') === false, 'workflow logic must remain separate from native vod_status.');

fwrite(STDOUT, "OK: video workflow transition contract passed.\n");
