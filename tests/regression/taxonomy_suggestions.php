<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$migration = (string) @file_get_contents($root . '/application/data/migrations/20260920000600_taxonomy_suggestions.sql');
foreach (['content_taxonomy_suggestion', 'matched_term_id', 'match_status', 'decision', 'uk_source_value'] as $needle) {
    if (strpos($migration, $needle) === false) { fwrite(STDERR, "FAIL: taxonomy migration missing {$needle}\n"); exit(1); }
}
require_once $root . '/application/common/util/TaxonomySuggestionService.php';

use app\common\util\TaxonomySuggestionService;

final class MemoryTaxonomySuggestions extends TaxonomySuggestionService
{
    public $terms = [];
    public $rows = [];
    public $locks = [];
    public $relations = [];
    public $synced = [];
    protected function loadActiveTerms(string $kind): array { return array_values(array_filter($this->terms, static fn(array $r): bool => $r['kind'] === $kind && (int) $r['status'] === 1)); }
    protected function persistSuggestion(array $row): array { $row['suggestion_id'] = count($this->rows) + 1; $this->rows[$row['suggestion_id']] = $row; return $row; }
    protected function loadSuggestionForUpdate(int $id): ?array { return $this->rows[$id] ?? null; }
    protected function updateSuggestion(int $id, array $changes): void { $this->rows[$id] = array_merge($this->rows[$id], $changes); }
    protected function taxonomyLocked(int $vodId, string $kind): bool { return !empty($this->locks[$vodId][$kind]); }
    protected function acceptedTermIds(int $vodId, string $kind): array { return array_values(array_filter(array_map(static fn(array $r): int => $r['vod_id'] === $vodId && $r['kind'] === $kind && $r['decision'] === 'accepted' ? (int) $r['matched_term_id'] : 0, $this->rows))); }
    protected function replaceTerms(int $vodId, string $kind, array $ids): void { $this->relations[$vodId][$kind] = $ids; }
    protected function syncNative(int $vodId): void { $this->synced[] = $vodId; }
    protected function transactional(callable $callback) { return $callback(); }
}

$service = new MemoryTaxonomySuggestions(static fn(): int => 500);
$service->terms = [
    ['term_id'=>1,'kind'=>'region','slug'=>'taiwan','name_tw'=>'台灣','name_cn'=>'台湾','name_en'=>'Taiwan','synonyms_json'=>'["TW"]','status'=>1],
    ['term_id'=>2,'kind'=>'genre','slug'=>'drama','name_tw'=>'劇情','name_cn'=>'剧情','name_en'=>'Drama','synonyms_json'=>'["dramatic"]','status'=>1],
    ['term_id'=>3,'kind'=>'genre','slug'=>'drama-alt','name_tw'=>'Drama','name_cn'=>'','name_en'=>'','synonyms_json'=>'[]','status'=>1],
    ['term_id'=>4,'kind'=>'tag','slug'=>'hidden','name_tw'=>'隱藏','name_cn'=>'','name_en'=>'Hidden','synonyms_json'=>'[]','status'=>0],
];

$region = $service->stage(9, 'ai', 'ai_run:3', ['regions'=>['TW'], 'genres'=>['Drama'], 'tags'=>['Unknown','Hidden']]);
assertTax($region[0]['match_status'] === 'matched' && $region[0]['matched_term_id'] === 1, 'unique synonym matches active term');
assertTax($region[1]['match_status'] === 'ambiguous' && $region[1]['matched_term_id'] === 0, 'multiple exact names remain ambiguous');
assertTax($region[2]['match_status'] === 'missing' && $region[3]['match_status'] === 'missing', 'unknown and disabled terms remain missing');

$accepted = $service->review(1, 'accept', 7, 'admin');
assertTax($accepted['decision'] === 'accepted' && $service->relations[9]['region'] === [1] && $service->synced === [9], 'reviewed unique match updates canonical relation and native projection');
try { $service->review(2, 'accept', 7, 'admin'); assertTax(false, 'ambiguous suggestion accepted'); } catch (RuntimeException $e) {}
$service->locks[9]['region'] = true;
try { $service->review(1, 'accept', 7, 'admin'); assertTax(false, 'manual taxonomy lock bypassed'); } catch (RuntimeException $e) {}
$rejected = $service->review(3, 'reject', 7, 'admin');
assertTax($rejected['decision'] === 'rejected', 'missing suggestion may be explicitly rejected');

$ai = (string) file_get_contents($root . '/application/common/util/AiNormalizationPipeline.php');
$tmdb = (string) file_get_contents($root . '/application/common/util/TmdbImportService.php');
assertTax(strpos($ai, 'taxonomySuggestions->stage') !== false, 'AI pipeline stages taxonomy suggestions');
assertTax(strpos($tmdb, 'taxonomySuggestions->stage') !== false, 'TMDB import stages taxonomy suggestions');

fwrite(STDOUT, "PASS: reviewed taxonomy suggestion contract\n");

function assertTax(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
