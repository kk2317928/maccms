<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sql = @file_get_contents($root . '/application/data/migrations/20260918000400_ai_runs.sql') ?: '';
foreach (['CREATE TABLE IF NOT EXISTS `__PREFIX__content_ai_run`', '`prompt_version` varchar(64)', '`response_fingerprint` char(64)', '`estimated_cost_micros` bigint(20) unsigned', 'KEY `idx_daily_usage` (`created_at`,`estimated_cost_micros`)'] as $needle) {
    if (strpos($sql, $needle) === false) { fwrite(STDERR, "FAIL: AI run migration missing {$needle}\n"); exit(1); }
}
$path = $root . '/application/common/util/AiRunRepository.php';
if (!is_file($path)) { fwrite(STDERR, "FAIL: AiRunRepository is missing.\n"); exit(1); }
require_once $path;

use app\common\util\AiRunRepository;

final class MemoryAiRunRepository extends AiRunRepository
{
    public $rows = [];
    protected function insertRun(array $row): int { $row['ai_run_id'] = count($this->rows) + 1; $this->rows[] = $row; return $row['ai_run_id']; }
    protected function sumUsage(int $from, int $to): array
    {
        $cost = $tokens = 0;
        foreach ($this->rows as $row) { if ($row['created_at'] >= $from && $row['created_at'] < $to) { $cost += $row['estimated_cost_micros']; $tokens += $row['total_tokens']; } }
        return ['cost_micros' => $cost, 'total_tokens' => $tokens];
    }
}

$repo = new MemoryAiRunRepository(static fn (): int => 1726704000);
$id = $repo->record([
    'vod_id' => 42, 'job_id' => 7, 'provider' => 'openai-compatible', 'model' => 'model-x',
    'prompt_version' => 'normalize-v1', 'request_body' => '{"title":"X"}', 'raw_response' => '{"ok":true}',
    'validation_status' => 'valid', 'decision_status' => 'pending',
    'input_tokens' => 100, 'output_tokens' => 20, 'estimated_cost_micros' => 900,
]);
if ($id !== 1 || $repo->rows[0]['total_tokens'] !== 120 || $repo->rows[0]['response_fingerprint'] !== hash('sha256', '{"ok":true}')) {
    fwrite(STDERR, "FAIL: immutable AI run provenance was not recorded.\n"); exit(1);
}
$usage = $repo->dailyUsage(1726704000);
if ($usage !== ['cost_micros' => 900, 'total_tokens' => 120]) { fwrite(STDERR, "FAIL: daily usage aggregate is incorrect.\n"); exit(1); }
if (!$repo->withinDailyBudget(1000, 1726704000) || $repo->withinDailyBudget(900, 1726704000)) { fwrite(STDERR, "FAIL: daily budget boundary is incorrect.\n"); exit(1); }

try { $repo->record(['vod_id' => 0]); } catch (InvalidArgumentException $exception) { fwrite(STDOUT, "OK: immutable AI run and usage contract passed.\n"); exit(0); }
fwrite(STDERR, "FAIL: invalid AI run must be rejected.\n"); exit(1);
