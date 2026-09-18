<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$migration = @file_get_contents($root . '/application/data/migrations/20260918000700_content_merge_snapshots.sql') ?: '';
foreach (['CREATE TABLE IF NOT EXISTS `__PREFIX__content_merge_snapshot`', '`snapshot_json` mediumtext NOT NULL', '`snapshot_hash` char(64)', 'UNIQUE KEY `uk_duplicate_candidate` (`duplicate_candidate_id`)'] as $needle) {
    if (strpos($migration, $needle) === false) { fwrite(STDERR, "FAIL: merge snapshot migration missing {$needle}\n"); exit(1); }
}
foreach (['VodPlaybackCodec.php', 'DuplicateMergeService.php'] as $file) {
    $path = $root . '/application/common/util/' . $file;
    if (!is_file($path)) { fwrite(STDERR, "FAIL: {$file} is missing.\n"); exit(1); }
    require_once $path;
}

use app\common\util\DuplicateMergeService;

final class MemoryDuplicateMergeService extends DuplicateMergeService
{
    public $candidate;
    public $bundles;
    public $snapshots = [];
    public $failAfterPrimary = false;
    private $transactionState;
    public function __construct(array $candidate, array $bundles, callable $clock) { parent::__construct($clock); $this->candidate = $candidate; $this->bundles = $bundles; }
    protected function beginTransaction(): void { $this->transactionState = serialize([$this->candidate, $this->bundles, $this->snapshots]); }
    protected function commitTransaction(): void { $this->transactionState = null; }
    protected function rollbackTransaction(): void { [$this->candidate, $this->bundles, $this->snapshots] = unserialize($this->transactionState); }
    protected function loadCandidate(int $candidateId): ?array { return $candidateId === $this->candidate['duplicate_candidate_id'] ? $this->candidate : null; }
    protected function loadBundle(int $vodId): array { return $this->bundles[$vodId]; }
    protected function insertSnapshot(array $row): int { $row['merge_snapshot_id'] = count($this->snapshots) + 1; $this->snapshots[] = $row; return $row['merge_snapshot_id']; }
    protected function updatePrimaryPlayback(int $vodId, array $playback): void { $this->bundles[$vodId]['vod'] = array_merge($this->bundles[$vodId]['vod'], $playback); if ($this->failAfterPrimary) throw new RuntimeException('injected failure'); }
    protected function markSecondaryMerged(int $secondaryVodId, int $primaryVodId, int $now): void { $this->bundles[$secondaryVodId]['ext']['workflow_status'] = 'merged'; $this->bundles[$secondaryVodId]['ext']['merged_into_vod_id'] = $primaryVodId; }
    protected function markCandidateMerged(int $candidateId, int $reviewerId, int $now): void { $this->candidate['decision'] = 'merged'; $this->candidate['reviewed_by'] = $reviewerId; $this->candidate['reviewed_at'] = $now; }
}

$candidate = ['duplicate_candidate_id' => 3, 'vod_id_low' => 7, 'vod_id_high' => 42, 'decision' => 'pending'];
$bundles = [
    7 => ['vod' => ['vod_id' => 7, 'vod_play_from' => 'main', 'vod_play_url' => '第1集$https://video.test/1#第2集$https://video.test/2', 'vod_play_server' => 'server-main', 'vod_play_note' => 'primary'], 'ext' => ['vod_id' => 7, 'workflow_status' => 'duplicate_review', 'merged_into_vod_id' => 0], 'meta_terms' => [['term_id' => 1]], 'field_states' => [['field_name' => 'title_tw']], 'external_maps' => [['provider_code' => 'tmdb']], 'content_lang' => [['lang_code' => 'en']], 'ulog' => [['ulog_id' => 1]]],
    42 => ['vod' => ['vod_id' => 42, 'vod_play_from' => 'main$$$backup', 'vod_play_url' => '副名稱$https://video.test/2#第3集$https://video.test/3$$$正片$https://backup.test/movie', 'vod_play_server' => 'secondary-main$$$', 'vod_play_note' => 'secondary$$$backup'], 'ext' => ['vod_id' => 42, 'workflow_status' => 'duplicate_review', 'merged_into_vod_id' => 0], 'meta_terms' => [['term_id' => 2]], 'field_states' => [['field_name' => 'title_en']], 'external_maps' => [['provider_code' => 'imdb']], 'content_lang' => [['lang_code' => 'zh-tw']], 'ulog' => [['ulog_id' => 2]]],
];
$service = new MemoryDuplicateMergeService($candidate, $bundles, static fn (): int => 1726704200);
$snapshotId = $service->merge(3, 7, 42, 99);
if ($snapshotId !== 1 || $service->candidate['decision'] !== 'merged' || $service->candidate['reviewed_by'] !== 99) { fwrite(STDERR, "FAIL: reviewed merge decision was not persisted.\n"); exit(1); }
$snapshot = json_decode($service->snapshots[0]['snapshot_json'], true);
if ($snapshot['primary'] !== $bundles[7] || $snapshot['secondary'] !== $bundles[42] || !hash_equals(hash('sha256', $service->snapshots[0]['snapshot_json']), $service->snapshots[0]['snapshot_hash'])) { fwrite(STDERR, "FAIL: immutable pre-merge bundle snapshot is incomplete.\n"); exit(1); }
$mergedVod = $service->bundles[7]['vod'];
if ($mergedVod['vod_play_from'] !== 'main$$$backup' || $mergedVod['vod_play_url'] !== '第1集$https://video.test/1#第2集$https://video.test/2#第3集$https://video.test/3$$$正片$https://backup.test/movie') { fwrite(STDERR, "FAIL: playback merge must retain primary order and deduplicate normalized URLs.\n"); exit(1); }
if ($service->bundles[42]['ext']['workflow_status'] !== 'merged' || $service->bundles[42]['ext']['merged_into_vod_id'] !== 7) { fwrite(STDERR, "FAIL: secondary video was not marked merged into the primary.\n"); exit(1); }

$failing = new MemoryDuplicateMergeService($candidate, $bundles, static fn (): int => 1726704200);
$failing->failAfterPrimary = true;
try { $failing->merge(3, 7, 42, 99); } catch (RuntimeException $exception) {}
if ($failing->bundles !== $bundles || $failing->candidate !== $candidate || $failing->snapshots) { fwrite(STDERR, "FAIL: any merge failure must roll back snapshot and content mutations.\n"); exit(1); }
try { $service->merge(3, 42, 7, 0); } catch (InvalidArgumentException $exception) { fwrite(STDOUT, "OK: transactional duplicate merge contract passed.\n"); exit(0); }
fwrite(STDERR, "FAIL: merge reviewer must be validated.\n"); exit(1);
