<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
foreach (['AiNormalizationValidator.php', 'AiNormalizationPipeline.php'] as $file) {
    $path = $root . '/application/common/util/' . $file;
    if (!is_file($path)) { fwrite(STDERR, "FAIL: {$file} is missing.\n"); exit(1); }
    require_once $path;
}

use app\common\util\AiNormalizationPipeline;
use app\common\util\AiNormalizationValidator;

final class PipelineRuns { public $budget = true; public $rows = []; public $staged = []; public function withinDailyBudget(int $limit, int $now): bool { return $this->budget; } public function record(array $row): int { $this->rows[] = $row; return count($this->rows); } public function stageReviews(int $runId, int $vodId, array $fields, int $now): void { $this->staged = $fields; } }
final class PipelineFields { public $writes = []; public $values = ['vod_name' => 'Before']; public function inspect(int $vodId, string $field): array { return ['value' => $this->values[$field] ?? null, 'state' => null]; } public function apply(int $vodId, string $field, $value, string $source, string $ref = ''): bool { $this->writes[] = compact('vodId', 'field', 'value', 'source', 'ref'); return true; } }

$valid = json_encode([
    'normalized_title' => 'Example', 'original_title' => 'Original', 'title_tw' => '範例', 'title_cn' => '范例', 'title_en' => 'Example',
    'aliases' => ['Alias'], 'year' => 2024, 'media_type' => 'movie',
    'tmdb_clues' => ['title' => 'Example', 'year' => 2024, 'type' => 'movie'],
    'taxonomy' => ['regions' => ['TW'], 'genres' => ['Drama'], 'tags' => ['Example']],
    'confidence' => 0.9, 'reason' => 'Structured normalization.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$calls = 0; $runs = new PipelineRuns(); $fields = new PipelineFields();
$provider = static function (array $request) use (&$calls, $valid): array { $calls++; return ['raw_response' => $valid, 'input_tokens' => 100, 'output_tokens' => 50, 'estimated_cost_micros' => 750]; };
$pipeline = new AiNormalizationPipeline($provider, new AiNormalizationValidator(), $runs, $fields, ['provider' => 'compatible', 'model' => 'model-x', 'prompt_version' => 'normalize-v1', 'daily_budget_micros' => 1000], static fn (): int => 1726704000);
$metrics = $pipeline->handle(['vod_id' => 42, 'title' => 'Example'], ['job_id' => 7]);
if ($calls !== 1 || $metrics['ai_run_id'] !== 1 || $metrics['fields_proposed'] !== 7 || $fields->writes || $runs->rows[0]['decision_status'] !== 'pending') { fwrite(STDERR, "FAIL: valid AI output must remain a pending review candidate without field mutation.\n"); exit(1); }
if (count($runs->staged) !== 7 || $runs->staged[0]['candidate'] !== 'Example' || $runs->staged[0]['baseline'] !== 'Before' || strlen($runs->staged[0]['baseline_hash']) !== 64) { fwrite(STDERR, "FAIL: valid output must stage canonical candidates with immutable baselines.\n"); exit(1); }

$runs->budget = false;
try { $pipeline->handle(['vod_id' => 42, 'title' => 'Example'], ['job_id' => 8]); } catch (RuntimeException $exception) {}
if ($calls !== 1) { fwrite(STDERR, "FAIL: exhausted budget must prevent the provider call.\n"); exit(1); }

$unlimitedCalls = 0; $unlimitedRuns = new PipelineRuns(); $unlimitedRuns->budget = false;
$unlimited = new AiNormalizationPipeline(static function () use (&$unlimitedCalls, $valid): array { $unlimitedCalls++; return ['raw_response' => $valid, 'input_tokens' => 1, 'output_tokens' => 1, 'estimated_cost_micros' => 1]; }, new AiNormalizationValidator(), $unlimitedRuns, new PipelineFields(), ['provider' => 'compatible', 'model' => 'model-x', 'prompt_version' => 'normalize-v1', 'daily_budget_micros' => 0], static fn (): int => 1726704000);
$unlimited->handle(['vod_id' => 42, 'title' => 'Example'], ['job_id' => 10]);
if ($unlimitedCalls !== 1) { fwrite(STDERR, "FAIL: zero daily budget must allow the provider call without a budget gate.\n"); exit(1); }

$badRuns = new PipelineRuns(); $badFields = new PipelineFields();
$bad = new AiNormalizationPipeline(static fn (): array => ['raw_response' => '{}', 'input_tokens' => 1, 'output_tokens' => 1, 'estimated_cost_micros' => 1], new AiNormalizationValidator(), $badRuns, $badFields, ['provider' => 'compatible', 'model' => 'model-x', 'prompt_version' => 'normalize-v1', 'daily_budget_micros' => 1000], static fn (): int => 1726704000);
try { $bad->handle(['vod_id' => 42, 'title' => 'Example'], ['job_id' => 9]); } catch (InvalidArgumentException $exception) {}
if ($badFields->writes || count($badRuns->rows) !== 1 || $badRuns->rows[0]['validation_status'] !== 'invalid') { fwrite(STDERR, "FAIL: invalid output must be recorded without field mutation.\n"); exit(1); }

fwrite(STDOUT, "OK: AI normalization pipeline integration contract passed.\n");
