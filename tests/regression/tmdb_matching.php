<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/application/common/util/TmdbMatchService.php';
if (!is_file($path)) { fwrite(STDERR, "FAIL: TmdbMatchService.php is missing.\n"); exit(1); }
require_once $path;

use app\common\util\TmdbMatchService;

function tmdb_assert($condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$service = new TmdbMatchService();
$video = [
    'original_title' => 'Original Work', 'title_en' => 'English Work', 'title_tw' => '繁體片名',
    'title_cn' => '简体片名', 'aliases' => ['Alias Work', 'English Work'], 'year' => 2024,
    'media_type' => 'movie', 'regions' => ['TW'], 'actors' => ['Actor One'], 'directors' => ['Director One'],
];
$queries = $service->buildQueries($video);
tmdb_assert(array_column($queries, 'title') === ['Original Work', 'English Work', '繁體片名', '简体片名', 'Alias Work'], 'search order must be original, English, Traditional Chinese, Simplified Chinese, then deduplicated aliases.');
tmdb_assert(count(array_filter($queries, static fn (array $query): bool => $query['year'] === 2024)) === 5, 'every first-pass query must prefer the known year.');

$result = $service->match($video, static function (array $query): array {
    if ($query['title'] !== 'Original Work') { return []; }
    return [
        ['id' => 90, 'media_type' => 'movie', 'title' => 'Original Work', 'original_title' => 'Original Work', 'year' => 2024, 'regions' => ['TW'], 'actors' => ['Actor One'], 'directors' => ['Director One']],
        ['id' => 91, 'media_type' => 'tv', 'title' => 'Original Work', 'year' => 2018, 'regions' => ['US'], 'actors' => []],
    ];
});
tmdb_assert($result['status'] === 'candidate_review' && $result['preselected_id'] === 90, 'one clearly superior candidate may be preselected for review.');
tmdb_assert($result['candidates'][0]['score'] > $result['candidates'][1]['score'], 'candidate scoring must combine title, year, type, region, and people evidence deterministically.');
tmdb_assert($result['requires_review'] === true, 'high confidence must never bypass final human review.');

$none = $service->match($video, static fn (array $query): array => []);
tmdb_assert($none === ['status' => 'no_match', 'candidates' => [], 'preselected_id' => 0, 'requires_review' => true], 'no reliable candidate must produce an explicit no-match result.');

$manual = $service->manual('tv', 777, static function (string $type, int $id): array {
    return ['id' => $id, 'media_type' => $type, 'title' => 'Manual Result', 'year' => 2020];
});
tmdb_assert($manual['status'] === 'candidate_review' && $manual['preselected_id'] === 777 && $manual['manual'] === true && $manual['requires_review'] === true, 'manual TMDB ID lookup must fetch an exact review candidate without auto-applying it.');

fwrite(STDOUT, "OK: deterministic TMDB matching contract passed.\n");
