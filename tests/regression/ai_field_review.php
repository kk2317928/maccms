<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$migration = @file_get_contents($root . '/application/data/migrations/20260919000100_ai_field_reviews.sql') ?: '';
foreach (['CREATE TABLE IF NOT EXISTS `__PREFIX__content_ai_field_review`', 'UNIQUE KEY `uk_run_field` (`ai_run_id`,`field_name`)', 'KEY `idx_vod_decision` (`vod_id`,`decision`)'] as $needle) {
    if (strpos($migration, $needle) === false) { fwrite(STDERR, "FAIL: AI field-review migration missing {$needle}\n"); exit(1); }
}
$baselineMigration = @file_get_contents($root . '/application/data/migrations/20260919000200_ai_field_review_baselines.sql') ?: '';
foreach (['information_schema.COLUMNS', '`baseline_hash` char(64)', "DEFAULT 'pending'", 'PREPARE ai_review_baseline_stmt'] as $needle) {
    if (strpos($baselineMigration, $needle) === false) { fwrite(STDERR, "FAIL: AI baseline migration missing {$needle}\n"); exit(1); }
}

foreach (['FieldGovernance.php', 'ContentAdminAudit.php', 'AiFieldReviewService.php'] as $file) {
    $path = $root . '/application/common/util/' . $file;
    if (!is_file($path)) { fwrite(STDERR, "FAIL: {$file} is missing.\n"); exit(1); }
    require_once $path;
}
$controller = @file_get_contents($root . '/application/admin/controller/ContentWorkspace.php') ?: '';
$template = @file_get_contents($root . '/application/admin/view_new/content_workspace/review.html') ?: '';
fieldReviewAssert(strpos($controller, "mac_admin_csrf_token") !== false && strpos($template, '{:mac_admin_csrf_token()}') !== false, 'review POSTs must use the stable admin CSRF token.');
foreach (['run.vod_name|default=\'--\'|htmlentities', 'run.provider|htmlentities', 'item.source_ref|htmlentities'] as $escaped) {
    fieldReviewAssert(strpos($template, $escaped) !== false, 'review output must escape ' . $escaped . '.');
}

use app\common\util\AiFieldReviewService;
use app\common\util\ContentAdminAudit;
use app\common\util\FieldGovernance;

function fieldReviewAssert($condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}

final class MemoryReviewGovernance extends FieldGovernance
{
    public $values = [];
    public $states = [];
    protected function loadState(int $vodId, string $field): ?array { return $this->states[$vodId][$field] ?? null; }
    protected function loadValue(int $vodId, string $field) { return $this->values[$vodId][$field] ?? null; }
    protected function persistValue(int $vodId, string $field, $value): void { $this->values[$vodId][$field] = $value; }
    protected function persistState(array $state): void { $this->states[$state['vod_id']][$state['field_name']] = $state; }
}

final class MemoryAiFieldReviewService extends AiFieldReviewService
{
    public $run;
    public $decisions = [];
    protected function loadRun(int $runId): ?array { return $runId === (int) $this->run['ai_run_id'] ? $this->run : null; }
    protected function loadDecision(int $runId, string $field): ?array { return $this->decisions[$field] ?? null; }
    protected function persistDecision(array $row): void { $this->decisions[$row['field_name']] = $row; }
    protected function countDecisions(int $runId): int { return count(array_filter($this->decisions, static fn (array $row): bool => $row['decision'] !== 'pending')); }
    protected function lockVideoRows(int $vodId): void {}
    protected function updateRunDecision(int $runId, string $status): void { $this->run['decision_status'] = $status; }
    protected function transactional(callable $callback) { return $callback(); }
}

$valid = json_encode([
    'normalized_title' => 'AI Name', 'original_title' => 'AI Original', 'title_tw' => 'AI 台灣名',
    'title_cn' => 'AI 中国名', 'title_en' => 'AI English', 'year' => 2024, 'media_type' => 'movie',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$governance = new MemoryReviewGovernance(static fn (): int => 120);
$governance->values[42] = ['vod_name' => 'Current', 'original_title' => 'Old Original', 'title_tw' => '舊名', 'title_cn' => '舊簡名', 'title_en' => 'Manual English', 'vod_year' => 2020, 'type2' => 'series'];
$governance->states[42] = [
    'title_tw' => ['source' => 'ai', 'source_ref' => 'ai_run:1', 'is_locked' => 0, 'updated_at' => 90],
    'title_en' => ['source' => 'manual', 'source_ref' => 'admin:2', 'is_locked' => 1, 'updated_at' => 90],
    'original_title' => ['source' => 'import', 'source_ref' => 'feed:1', 'is_locked' => 0, 'updated_at' => 110],
];
$events = [];
$audit = new ContentAdminAudit(static function (array $row) use (&$events): int { $events[] = $row; return count($events); }, static fn (): int => 120);
$service = new MemoryAiFieldReviewService($governance, $audit, static fn (): int => 120, null);
$service->run = ['ai_run_id' => 7, 'vod_id' => 42, 'public_id' => 'ABC234', 'validation_status' => 'valid', 'decision_status' => 'pending', 'provider' => 'compatible', 'model' => 'model-x', 'prompt_version' => 'v1', 'raw_response_json' => $valid, 'created_at' => 100];
$candidates = json_decode($valid, true);
$mapping = ['vod_name' => 'normalized_title', 'original_title' => 'original_title', 'title_tw' => 'title_tw', 'title_cn' => 'title_cn', 'title_en' => 'title_en', 'vod_year' => 'year', 'type2' => 'media_type'];
foreach ($mapping as $field => $key) {
    $baseline = $governance->values[42][$field];
    if ($field === 'original_title') { $baseline = 'Earlier Original'; }
    $service->decisions[$field] = [
        'candidate_value_json' => json_encode($candidates[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'baseline_value_json' => json_encode($baseline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'baseline_hash' => hash('sha256', json_encode($baseline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
        'decision' => 'pending', 'created_at' => 100,
    ];
}

$preview = $service->preview(7);
fieldReviewAssert($preview['fields']['title_tw']['current'] === '舊名' && $preview['fields']['title_tw']['candidate'] === 'AI 台灣名', 'preview must show current and AI candidate values.');
fieldReviewAssert($preview['fields']['title_en']['locked'] === true && $preview['fields']['title_en']['source'] === 'manual', 'preview must expose locks and provenance.');
fieldReviewAssert($preview['fields']['original_title']['stale'] === true, 'a field updated after the AI run must be marked stale.');

$accepted = $service->review(7, 'title_tw', 'accept', null, 55, 'reviewer');
fieldReviewAssert($accepted['decision'] === 'accepted' && $governance->values[42]['title_tw'] === 'AI 台灣名', 'accept must apply the exact AI candidate.');
fieldReviewAssert($governance->states[42]['title_tw']['source_ref'] === 'ai_run:7:admin:55', 'accepted AI values must retain run and reviewer provenance.');

foreach ([['title_en', 'locked'], ['original_title', 'stale']] as $case) {
    try { $service->review(7, $case[0], 'accept', null, 55, 'reviewer'); fieldReviewAssert(false, $case[1] . ' field accepted.'); }
    catch (RuntimeException $exception) { fieldReviewAssert(strpos($exception->getMessage(), $case[1]) !== false, $case[1] . ' conflict must be explicit.'); }
}

$service->review(7, 'vod_name', 'edit', 'Reviewed Name', 55, 'reviewer');
fieldReviewAssert($governance->values[42]['vod_name'] === 'Reviewed Name' && $governance->states[42]['vod_name']['source'] === 'manual' && $governance->states[42]['vod_name']['is_locked'] === 1, 'edited values must become manual locks.');
$beforeType = $governance->values[42]['type2'];
$service->review(7, 'type2', 'reject', null, 55, 'reviewer');
fieldReviewAssert($governance->values[42]['type2'] === $beforeType && $service->decisions['type2']['decision'] === 'rejected', 'reject must preserve the current value and record the decision.');
$service->review(7, 'vod_year', 'lock', null, 55, 'reviewer');
fieldReviewAssert($governance->values[42]['vod_year'] === 2020 && $governance->states[42]['vod_year']['is_locked'] === 1, 'lock must preserve and manually lock the current value.');
fieldReviewAssert(count($events) === 4, 'every successful field decision must append one audit event.');
fieldReviewAssert($events[0]['subject_public_id'] === 'ABC234', 'field-review audit events must identify videos by stable public ID.');
$service->review(7, 'vod_name', 'edit', 'Quoted " Name', 55, 'reviewer');
fieldReviewAssert($governance->values[42]['vod_name'] === 'Quoted &quot; Name', 'reviewed titles must use the native XSS canonicalization boundary.');

$failing = new MemoryAiFieldReviewService($governance, new ContentAdminAudit(static fn (array $row): int => 0), static fn (): int => 130, null);
$failing->run = $service->run;
$failing->decisions = $service->decisions;
$before = $governance->values[42]['title_cn'];
$beforeDecisions = $failing->decisions;
try { $failing->review(7, 'title_cn', 'accept', null, 55, 'reviewer'); fieldReviewAssert(false, 'audit failure must fail closed.'); } catch (RuntimeException $exception) {}
fieldReviewAssert($governance->values[42]['title_cn'] === $before && $failing->decisions === $beforeDecisions, 'audit failure must not mutate field value or decision state.');

foreach ([['vod_name', '<img src=x onerror=alert(1)>'], ['vod_name', str_repeat('"', 255)], ['vod_year', 'not-a-year'], ['type2', 'series']] as $invalidEdit) {
    try { $service->review(7, $invalidEdit[0], 'edit', $invalidEdit[1], 55, 'reviewer'); fieldReviewAssert(false, 'invalid manual edit was accepted.'); }
    catch (InvalidArgumentException $exception) {}
}
$safeCandidate = $service->decisions['title_cn']['candidate_value_json'];
$service->decisions['title_cn']['candidate_value_json'] = json_encode('<svg onload=alert(1)>');
try { $service->review(7, 'title_cn', 'accept', null, 55, 'reviewer'); fieldReviewAssert(false, 'unsafe stored candidate was accepted.'); }
catch (InvalidArgumentException $exception) {}
$service->decisions['title_cn']['candidate_value_json'] = $safeCandidate;
$missingIdentity = new MemoryAiFieldReviewService($governance, $audit, static fn (): int => 120, null);
$missingIdentity->run = array_merge($service->run, ['public_id' => '']);
try { $missingIdentity->preview(7); fieldReviewAssert(false, 'missing stable public ID must fail closed.'); } catch (RuntimeException $exception) {}
$missingIdentity->run = array_merge($service->run, ['public_id' => '000000']);
try { $missingIdentity->preview(7); fieldReviewAssert(false, 'non-canonical stable public ID must fail closed.'); } catch (RuntimeException $exception) {}

$service->review(7, 'title_cn', 'reject', null, 55, 'reviewer');
$service->review(7, 'title_en', 'reject', null, 55, 'reviewer');
$service->review(7, 'original_title', 'reject', null, 55, 'reviewer');
fieldReviewAssert($service->run['decision_status'] === 'reviewed', 'a run must leave the pending queue after every proposed field is decided.');

fwrite(STDOUT, "OK: AI field-level review, provenance, lock and conflict contract passed.\n");
