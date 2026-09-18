<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$migration = @file_get_contents($root . '/application/data/migrations/20260918000800_content_admin_audit_events.sql') ?: '';
if (strpos($migration, 'CREATE TABLE IF NOT EXISTS `__PREFIX__content_admin_audit_event`') === false) {
    fwrite(STDERR, "FAIL: immutable content-admin audit migration is missing.\n"); exit(1);
}
foreach (['ContentAdminPolicy.php', 'ContentAdminAudit.php'] as $file) {
    $path = $root . '/application/common/util/' . $file;
    if (!is_file($path)) { fwrite(STDERR, "FAIL: {$file} is missing.\n"); exit(1); }
    require_once $path;
}

use app\common\util\ContentAdminAudit;
use app\common\util\ContentAdminPolicy;

function security_assert($condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$policy = new ContentAdminPolicy();
$cases = [
    'view' => 'content_workspace/view', 'run_ai' => 'content_workspace/run_ai',
    'run_tmdb' => 'content_workspace/run_tmdb', 'review' => 'content_workspace/review',
    'merge_restore' => 'content_workspace/merge_restore', 'publish' => 'content_workspace/publish',
    'bulk_overwrite' => 'content_workspace/bulk_overwrite', 'security' => 'content_workspace/security',
];
foreach ($cases as $action => $permission) {
    try { $policy->assertAllowed($action, []); security_assert(false, "{$action} must default deny."); } catch (RuntimeException $exception) {}
    security_assert($policy->assertAllowed($action, [$permission]) === true, "{$action} must require its exact permission.");
}
foreach (['merge_restore', 'publish', 'bulk_overwrite', 'security'] as $action) {
    try { $policy->assertAllowed($action, [$cases[$action]], false); security_assert(false, "{$action} must require confirmation."); } catch (RuntimeException $exception) {}
    security_assert($policy->assertAllowed($action, [$cases[$action]], true) === true, "{$action} confirmation must authorize the exact action.");
}

$rows = [];
$audit = new ContentAdminAudit(static function (array $row) use (&$rows): int { $rows[] = $row; return count($rows); }, static fn (): int => 500);
$id = $audit->append(9, 'editor', 'content.publish', 'vod', 'ABC234', ['status' => 'review'], ['status' => 'published'], [
    'request_id' => 'req-1', 'api_key' => 'secret-value', 'nested' => ['token' => 'secret-token'],
]);
security_assert($id === 1 && count($rows) === 1, 'audit append must persist exactly one immutable event.');
$row = $rows[0];
security_assert($row['actor_id'] === 9 && $row['event_code'] === 'content.publish' && $row['subject_public_id'] === 'ABC234' && $row['created_at'] === 500, 'audit event identity must be complete.');
security_assert($row['before_hash'] === hash('sha256', '{"status":"review"}') && $row['after_hash'] === hash('sha256', '{"status":"published"}'), 'audit event must retain deterministic before/after hashes.');
$context = json_decode($row['context_json'], true);
security_assert($context['api_key'] === '[redacted]' && $context['nested']['token'] === '[redacted]' && $context['request_id'] === 'req-1', 'audit context must recursively redact secrets without losing traceability.');

try {
    (new ContentAdminAudit(static fn (array $row): int => 0))->append(9, 'editor', 'content.publish', 'vod', 'ABC234', [], [], []);
    security_assert(false, 'audit persistence failure must fail closed.');
} catch (RuntimeException $exception) {}

fwrite(STDOUT, "OK: granular content-admin permission and immutable audit contracts passed.\n");
