<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/application/common/util/VodWorkflow.php';
require_once dirname(__DIR__, 2) . '/application/common/util/ContentWorkflowCoordinator.php';

use app\common\util\ContentWorkflowCoordinator;
$rows = [7 => [
    'vod_id' => 7,
    'workflow_status' => 'imported',
    'ai_completed_at' => 0,
    'duplicate_checked_at' => 0,
    'tmdb_completed_at' => 0,
]];
$jobs = [];
$now = 1700000000;

$load = static function (int $vodId) use (&$rows): array {
    if (!isset($rows[$vodId])) {
        throw new RuntimeException('missing video');
    }
    return $rows[$vodId];
};
$save = static function (int $vodId, array $changes) use (&$rows): void {
    $rows[$vodId] = array_merge($rows[$vodId], $changes);
};
$transaction = static function (callable $callback) {
    return $callback();
};
$enqueue = static function (string $type, array $payload, string $key) use (&$jobs): array {
    if (!isset($jobs[$key])) {
        $jobs[$key] = ['job_id' => count($jobs) + 1, 'job_type' => $type, 'payload' => $payload, 'idempotency_key' => $key];
    }
    return $jobs[$key];
};

$coordinator = new ContentWorkflowCoordinator($load, $save, $transaction, $enqueue, static function () use (&$now): int {
    return $now;
});

$started = $coordinator->beginAi(7, 'abc123');
assertSame('ai_processing', $rows[7]['workflow_status'], 'beginAi enters ai_processing');
assertSame('video:7:ai:abc123', $started['idempotency_key'], 'AI key is deterministic');
$coordinator->beginAi(7, 'abc123');
assertSame(1, count($jobs), 'repeated beginAi is idempotent');

$now++;
$result = $coordinator->completeAi(7, 41, true);
assertSame('duplicate_review', $rows[7]['workflow_status'], 'AI waits for duplicate review when candidates exist');
assertSame($now, $rows[7]['ai_completed_at'], 'AI completion timestamp persisted');
assertSame(null, $result['next_job'], 'duplicate wait has no downstream job');

$now++;
$tmdb = $coordinator->completeDuplicateReview(7);
assertSame('tmdb_matching', $rows[7]['workflow_status'], 'duplicate review enters TMDB matching');
assertSame($now, $rows[7]['duplicate_checked_at'], 'duplicate timestamp persisted');
assertSame('video:7:tmdb', $tmdb['idempotency_key'], 'TMDB key is deterministic');

$now++;
$coordinator->completeTmdbReview(7, 92);
assertSame('manual_review', $rows[7]['workflow_status'], 'TMDB review enters manual review');
assertSame($now, $rows[7]['tmdb_completed_at'], 'TMDB timestamp persisted');

$rows[8] = $rows[7];
$rows[8]['vod_id'] = 8;
$rows[8]['workflow_status'] = 'ai_processing';
$now++;
$auto = $coordinator->completeAi(8, 43, false);
assertSame('tmdb_matching', $rows[8]['workflow_status'], 'no duplicate candidate advances directly to TMDB');
assertSame('video:8:tmdb', $auto['next_job']['idempotency_key'], 'no-duplicate path enqueues TMDB once');

$rows[9] = $rows[7];
$rows[9]['vod_id'] = 9;
$rows[9]['workflow_status'] = 'tmdb_matching';
$coordinator->failStage(9, 'tmdb_matching', 'provider.timeout');
assertSame('failed', $rows[9]['workflow_status'], 'stage failure enters failed');
$retry = $coordinator->beginAi(9, 'retry');
assertSame('ai_processing', $rows[9]['workflow_status'], 'failed content can restart safely at AI');
assertSame('video:9:ai:retry', $retry['idempotency_key'], 'retry remains idempotent');

$rows[10] = $rows[7];
$rows[10]['vod_id'] = 10;
$rows[10]['workflow_status'] = 'published';
expectDomainException(static function () use ($coordinator): void {
    $coordinator->beginAi(10, 'illegal');
}, 'terminal states reject mutation');

$rows[11] = $rows[7];
$rows[11]['vod_id'] = 11;
$rows[11]['workflow_status'] = 'imported';
expectDomainException(static function () use ($coordinator): void {
    $coordinator->completeTmdbReview(11, 1);
}, 'illegal stage skips fail closed');

fwrite(STDOUT, "PASS: content workflow coordinator contract\n");

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function expectDomainException(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (\DomainException $exception) {
        return;
    }
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}
