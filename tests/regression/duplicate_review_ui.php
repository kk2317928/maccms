<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
foreach (['DuplicateReviewWorkspace.php'] as $file) {
    $path = $root . '/application/common/util/' . $file;
    if (!is_file($path)) { fwrite(STDERR, "FAIL: {$file} is missing.\n"); exit(1); }
    require_once $path;
}

use app\common\util\DuplicateReviewWorkspace;

function duplicateUiAssert($condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}

final class MemoryDuplicateReviewWorkspace extends DuplicateReviewWorkspace
{
    public $candidate;
    public $videos;
    public $snapshot;
    protected function readQueueRows(int $offset, int $limit): array { return [$this->candidate]; }
    protected function countQueueRows(): int { return 1; }
    protected function loadCandidate(int $candidateId): ?array { return $candidateId === 3 ? $this->candidate : null; }
    protected function loadVideo(int $vodId): ?array { return $this->videos[$vodId] ?? null; }
    protected function loadSnapshotByCandidate(int $candidateId): ?array { return $candidateId === 3 ? $this->snapshot : null; }
    protected function loadSnapshot(int $snapshotId): ?array { return $snapshotId === 8 ? $this->snapshot : null; }
}

$service = new MemoryDuplicateReviewWorkspace();
$service->candidate = [
    'duplicate_candidate_id' => 3, 'vod_id_low' => 7, 'vod_id_high' => 42,
    'score' => 880, 'evidence_json' => '["same_tmdb_id","title_year"]',
    'decision' => 'pending', 'updated_at' => 100,
];
$service->videos = [
    7 => ['vod' => ['vod_id' => 7, 'vod_name' => 'Primary', 'vod_year' => '2024', 'vod_play_from' => 'main', 'vod_play_url' => '1$https://a/1'], 'ext' => ['public_id' => 'ABC234', 'title_tw' => '主片', 'type2' => 'movie'], 'content_lang' => [['lang_code' => 'en', 'title' => 'Primary']], 'external_maps' => [['provider_code' => 'tmdb', 'external_id' => '100']], 'field_states' => []],
    42 => ['vod' => ['vod_id' => 42, 'vod_name' => '<img src=x onerror=1>', 'vod_year' => '2024', 'vod_play_from' => 'backup', 'vod_play_url' => '1$https://b/1'], 'ext' => ['public_id' => 'BCD345', 'title_tw' => '副片', 'type2' => 'movie'], 'content_lang' => [], 'external_maps' => [['provider_code' => 'tmdb', 'external_id' => '100']], 'field_states' => [['field_name' => 'title_tw', 'source' => 'manual', 'is_locked' => 1]]],
];
$payload = ['version' => 1, 'primary' => $service->videos[7], 'secondary' => $service->videos[42]];
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$service->snapshot = ['merge_snapshot_id' => 8, 'duplicate_candidate_id' => 3, 'primary_vod_id' => 7, 'secondary_vod_id' => 42, 'snapshot_json' => $json, 'snapshot_hash' => hash('sha256', $json), 'status' => 'active', 'merged_by' => 55, 'merged_at' => 200];

$queue = $service->queue(1, 20);
duplicateUiAssert($queue['total'] === 1 && $queue['rows'][0]['score'] === 880, 'queue must expose deterministic score and paging.');
$comparison = $service->compare(3);
duplicateUiAssert($comparison['evidence'] === ['same_tmdb_id', 'title_year'], 'comparison must decode evidence deterministically.');
duplicateUiAssert($comparison['left']['ext']['public_id'] === 'ABC234' && $comparison['right']['field_states'][0]['is_locked'] === 1, 'comparison must expose stable identity, provenance and locks for both videos.');
$preview = $service->snapshotPreview(8);
duplicateUiAssert($preview['snapshot']['status'] === 'active' && $preview['payload']['primary']['ext']['public_id'] === 'ABC234', 'restore preview must verify and expose the immutable snapshot.');
$service->snapshot['snapshot_hash'] = str_repeat('0', 64);
try { $service->snapshotPreview(8); duplicateUiAssert(false, 'tampered snapshot was previewed.'); } catch (RuntimeException $exception) {}

$controller = @file_get_contents($root . '/application/admin/controller/ContentWorkspace.php') ?: '';
$template = @file_get_contents($root . '/application/admin/view_new/content_workspace/merge_restore.html') ?: '';
foreach (['function mergeRestore', "assertAllowed('merge_restore'", 'DuplicateCandidateDecisionService', 'DuplicateMergeService', 'DuplicateRestoreService', 'mac_admin_csrf_token'] as $needle) {
    duplicateUiAssert(strpos($controller, $needle) !== false, 'merge/restore controller contract missing ' . $needle . '.');
}
foreach (['mac_admin_csrf_token()', '|htmlentities', 'data-action="merge"', 'data-action="restore"', 'data-action="different"', 'confirm'] as $needle) {
    duplicateUiAssert(strpos($template, $needle) !== false, 'merge/restore view contract missing ' . $needle . '.');
}

fwrite(STDOUT, "OK: duplicate comparison, merge confirmation and restore preview UI contract passed.\n");
