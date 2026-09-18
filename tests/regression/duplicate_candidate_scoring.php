<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$migration = @file_get_contents($root . '/application/data/migrations/20260918000500_duplicate_candidates.sql') ?: '';
foreach ([
    'CREATE TABLE IF NOT EXISTS `__PREFIX__content_duplicate_candidate`',
    'UNIQUE KEY `uk_candidate_pair` (`vod_id_low`,`vod_id_high`)',
    '`evidence_json` text NOT NULL',
    '`score` smallint(5) unsigned NOT NULL',
    '`decision` varchar(16)',
    '`reviewed_by` int(10) unsigned',
] as $needle) {
    if (strpos($migration, $needle) === false) { fwrite(STDERR, "FAIL: duplicate-candidate migration missing {$needle}\n"); exit(1); }
}

foreach (['DuplicateCandidateScorer.php', 'DuplicateCandidateRepository.php'] as $file) {
    $path = $root . '/application/common/util/' . $file;
    if (!is_file($path)) { fwrite(STDERR, "FAIL: {$file} is missing.\n"); exit(1); }
    require_once $path;
}

use app\common\util\DuplicateCandidateRepository;
use app\common\util\DuplicateCandidateScorer;

function duplicate_fixture(array $changes = []): array
{
    return array_merge([
        'tmdb_id' => 0, 'original_title' => '', 'title_tw' => '', 'title_cn' => '', 'title_en' => '',
        'aliases' => [], 'year' => 0, 'media_type' => '', 'actors' => [], 'directors' => [],
    ], $changes);
}

$scorer = new DuplicateCandidateScorer();
$cases = [
    'tmdb' => [duplicate_fixture(['tmdb_id' => 88]), duplicate_fixture(['tmdb_id' => 88]), 1000, 'tmdb_id'],
    'original' => [duplicate_fixture(['original_title' => 'My Film', 'year' => 2024, 'media_type' => 'movie']), duplicate_fixture(['original_title' => 'my-film', 'year' => 2024, 'media_type' => 'movie']), 900, 'original_title_year_type'],
    'multilingual' => [duplicate_fixture(['title_tw' => '電影甲', 'year' => 2024]), duplicate_fixture(['aliases' => ['電影甲'], 'year' => 2024]), 750, 'multilingual_title_year'],
    'people' => [duplicate_fixture(['title_en' => 'Shared Name', 'actors' => ['Actor A']]), duplicate_fixture(['title_en' => 'shared name', 'directors' => ['Actor A']]), 650, 'title_people'],
    'weak' => [duplicate_fixture(['title_en' => 'Unknown Year']), duplicate_fixture(['title_en' => 'unknown-year']), 350, 'title_only_unknown_year'],
];
foreach ($cases as $name => [$left, $right, $score, $reason]) {
    $result = $scorer->score($left, $right);
    $reverse = $scorer->score($right, $left);
    if ($result !== $reverse || $result['score'] !== $score || $result['evidence'][0] !== $reason) {
        fwrite(STDERR, "FAIL: deterministic {$name} scoring is incorrect.\n"); exit(1);
    }
}
$conflict = $scorer->score(
    duplicate_fixture(['title_en' => 'Same Name', 'year' => 2020]),
    duplicate_fixture(['title_en' => 'Same Name', 'year' => 2024])
);
if ($conflict['score'] >= 350 || !in_array('year_conflict', $conflict['evidence'], true)) {
    fwrite(STDERR, "FAIL: same-name different-year works must remain weak candidates.\n"); exit(1);
}

final class MemoryDuplicateCandidateRepository extends DuplicateCandidateRepository
{
    public $rows = [];
    protected function persist(array $row): int { $this->rows[] = $row; return count($this->rows); }
}
$repo = new MemoryDuplicateCandidateRepository(static fn (): int => 1726704000);
$id = $repo->record(42, 7, $cases['original'][2], ['original_title_year_type']);
$row = $repo->rows[0];
if ($id !== 1 || $row['vod_id_low'] !== 7 || $row['vod_id_high'] !== 42 || $row['decision'] !== 'pending' || $row['reviewed_by'] !== 0) {
    fwrite(STDERR, "FAIL: candidate persistence must canonicalize the pair and remain pending.\n"); exit(1);
}
try { $repo->record(7, 7, 100, []); } catch (InvalidArgumentException $exception) { fwrite(STDOUT, "OK: duplicate candidate schema and deterministic scoring contract passed.\n"); exit(0); }
fwrite(STDERR, "FAIL: a video cannot be its own duplicate candidate.\n"); exit(1);
