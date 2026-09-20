<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
foreach (['FieldGovernance.php', 'TmdbImportService.php'] as $file) {
    $path = $root . '/application/common/util/' . $file;
    if (!is_file($path)) { fwrite(STDERR, "FAIL: {$file} is missing.\n"); exit(1); }
    require_once $path;
}

use app\common\util\FieldGovernance;
use app\common\util\TmdbImportService;

final class MemoryTmdbGovernance extends FieldGovernance
{
    public $states = [];
    public $values = [];
    protected function loadState(int $vodId, string $field): ?array { return $this->states[$vodId][$field] ?? null; }
    protected function loadValue(int $vodId, string $field) { return $this->values[$vodId][$field] ?? null; }
    protected function persistValue(int $vodId, string $field, $value): void { $this->values[$vodId][$field] = $value; }
    protected function persistState(array $state): void { $this->states[$state['vod_id']][$state['field_name']] = $state; }
}

function tmdb_import_assert($condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$fields = new MemoryTmdbGovernance(static fn (): int => 400);
$fields->apply(42, 'title_tw', '人工片名', 'manual', 'admin:7');
$fields->apply(42, 'title_cn', 'AI 简名', 'ai', 'job:8');
$fields->apply(42, 'vod_content', 'Import summary', 'import', 'collect:2');
$candidate = [
    'id' => 123, 'media_type' => 'movie', 'original_title' => 'Original', 'title_tw' => 'TMDB 繁名',
    'title_cn' => '', 'title_en' => 'English', 'overview' => 'TMDB summary', 'year' => 2024,
    'regions' => ['TW', 'JP'], 'genres' => ['Drama'], 'actors' => ['Actor'], 'directors' => ['Director'],
    'poster_url' => 'https://image.test/poster.jpg', 'backdrop_url' => 'https://image.test/backdrop.jpg',
    'trailer_url' => 'https://video.test/trailer', 'score' => 8.4,
];
$service = new TmdbImportService(null);
$preview = $service->preview(42, $candidate, $fields);
tmdb_import_assert($preview['title_tw']['current'] === '人工片名' && $preview['title_tw']['locked'] === true, 'difference preview must expose current value and manual lock.');
tmdb_import_assert(!isset($preview['title_cn']), 'missing TMDB locale values must remain untouched for AI fallback.');

$result = $service->apply(42, $candidate, ['title_tw', 'title_en', 'vod_content', 'tmdb_id', 'tmdb_type'], $fields, 55);
tmdb_import_assert($result['applied'] === ['title_en', 'vod_content', 'tmdb_id', 'tmdb_type'] && $result['blocked'] === ['title_tw'], 'reviewed import must apply selected fields while preserving manual locks.');
tmdb_import_assert($fields->states[42]['title_en']['source'] === 'tmdb' && $fields->states[42]['title_en']['source_ref'] === 'tmdb:movie:123:admin:55', 'every imported field must retain exact TMDB and reviewer provenance.');
tmdb_import_assert($fields->values[42]['title_cn'] === 'AI 简名', 'TMDB must not erase an AI fallback when a locale value is missing.');

$override = $service->apply(42, $candidate, ['title_tw'], $fields, 55, true);
tmdb_import_assert($override['applied'] === ['title_tw'] && $fields->values[42]['title_tw'] === 'TMDB 繁名', 'an explicit reviewed override may replace and unlock a manual field.');

fwrite(STDOUT, "OK: reviewed TMDB import provenance and lock contract passed.\n");
