<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
foreach (['VodPlaybackCodec.php', 'DuplicateRestoreService.php'] as $file) {
    $path = $root . '/application/common/util/' . $file;
    if (!is_file($path)) { fwrite(STDERR, "FAIL: {$file} is missing.\n"); exit(1); }
    require_once $path;
}

use app\common\util\DuplicateRestoreService;

final class MemoryDuplicateRestoreService extends DuplicateRestoreService
{
    public $snapshot;
    public $candidate;
    public $bundles;
    private $transactionState;

    public function __construct(array $snapshot, array $candidate, array $bundles, callable $clock)
    {
        parent::__construct($clock);
        $this->snapshot = $snapshot;
        $this->candidate = $candidate;
        $this->bundles = $bundles;
    }

    protected function beginTransaction(): void { $this->transactionState = serialize([$this->snapshot, $this->candidate, $this->bundles]); }
    protected function commitTransaction(): void { $this->transactionState = null; }
    protected function rollbackTransaction(): void { [$this->snapshot, $this->candidate, $this->bundles] = unserialize($this->transactionState); }
    protected function loadSnapshot(int $snapshotId): ?array { return $snapshotId === $this->snapshot['merge_snapshot_id'] ? $this->snapshot : null; }
    protected function loadCandidate(int $candidateId): ?array { return $candidateId === $this->candidate['duplicate_candidate_id'] ? $this->candidate : null; }
    protected function loadBundle(int $vodId): array { return $this->bundles[$vodId]; }
    protected function restoreBundle(int $vodId, array $bundle): void { $this->bundles[$vodId] = $bundle; }
    protected function reopenCandidate(int $candidateId, int $now): void { $this->candidate = array_merge($this->candidate, ['decision' => 'pending', 'reviewed_by' => 0, 'reviewed_at' => 0, 'updated_at' => $now]); }
    protected function markSnapshotRestored(int $snapshotId, int $reviewerId, int $now): void { $this->snapshot = array_merge($this->snapshot, ['status' => 'restored', 'restored_by' => $reviewerId, 'restored_at' => $now]); }
}

function restore_assert($condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$primaryBefore = ['vod' => ['vod_id' => 7, 'vod_play_from' => 'main', 'vod_play_url' => '1$https://a/1', 'vod_play_server' => '', 'vod_play_note' => ''], 'ext' => ['vod_id' => 7, 'workflow_status' => 'duplicate_review', 'merged_into_vod_id' => 0, 'updated_at' => 100], 'meta_terms' => [['term_id' => 1]], 'field_states' => [], 'external_maps' => [], 'content_lang' => [], 'ulog' => []];
$secondaryBefore = ['vod' => ['vod_id' => 42, 'vod_play_from' => 'main', 'vod_play_url' => '2$https://a/2', 'vod_play_server' => '', 'vod_play_note' => ''], 'ext' => ['vod_id' => 42, 'workflow_status' => 'duplicate_review', 'merged_into_vod_id' => 0, 'updated_at' => 101], 'meta_terms' => [['term_id' => 2]], 'field_states' => [], 'external_maps' => [], 'content_lang' => [], 'ulog' => []];
$payload = ['version' => 1, 'primary' => $primaryBefore, 'secondary' => $secondaryBefore];
$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
$snapshot = ['merge_snapshot_id' => 8, 'duplicate_candidate_id' => 3, 'primary_vod_id' => 7, 'secondary_vod_id' => 42, 'snapshot_json' => $json, 'snapshot_hash' => hash('sha256', $json), 'status' => 'active', 'merged_by' => 99, 'merged_at' => 200, 'restored_by' => 0, 'restored_at' => 0];
$candidate = ['duplicate_candidate_id' => 3, 'decision' => 'merged', 'reviewed_by' => 99, 'reviewed_at' => 200, 'updated_at' => 200];
$primaryAfter = $primaryBefore;
$primaryAfter['vod']['vod_play_url'] = '1$https://a/1#2$https://a/2';
$secondaryAfter = $secondaryBefore;
$secondaryAfter['ext'] = array_merge($secondaryAfter['ext'], ['workflow_status' => 'merged', 'merged_into_vod_id' => 7, 'updated_at' => 200]);

$authorized = static function (int $reviewerId, string $permission): bool { return $reviewerId === 55 && $permission === 'content_duplicate_restore'; };
$service = new MemoryDuplicateRestoreService($snapshot, $candidate, [7 => $primaryAfter, 42 => $secondaryAfter], static fn (): int => 300);
$service->restore(8, 55, true, $authorized);
restore_assert($service->bundles[7] === $primaryBefore && $service->bundles[42] === $secondaryBefore, 'restore must return every snapshotted value and relationship.');
restore_assert($service->candidate['decision'] === 'pending' && $service->candidate['reviewed_by'] === 0, 'restore must reopen the duplicate candidate.');
restore_assert($service->snapshot['status'] === 'restored' && $service->snapshot['restored_by'] === 55 && $service->snapshot['restored_at'] === 300, 'restore must retain a complete actor/timestamp audit record.');

foreach ([[54, true], [55, false]] as [$reviewerId, $confirmed]) {
    $denied = new MemoryDuplicateRestoreService($snapshot, $candidate, [7 => $primaryAfter, 42 => $secondaryAfter], static fn (): int => 300);
    try { $denied->restore(8, $reviewerId, $confirmed, $authorized); } catch (RuntimeException $exception) { continue; }
    restore_assert(false, 'restore must require exact authorization and explicit confirmation.');
}

$conflicting = new MemoryDuplicateRestoreService($snapshot, $candidate, [7 => $primaryAfter, 42 => $secondaryAfter], static fn (): int => 300);
$conflicting->bundles[7]['vod']['vod_name'] = 'edited after merge';
try { $conflicting->restore(8, 55, true, $authorized); } catch (RuntimeException $exception) {
    restore_assert(strpos($exception->getMessage(), 'conflict') !== false, 'post-merge edits must produce an explicit restoration conflict.');
    restore_assert($conflicting->snapshot === $snapshot && $conflicting->candidate === $candidate, 'conflicted restoration must roll back atomically.');
    fwrite(STDOUT, "OK: authorized conflict-aware duplicate restoration contract passed.\n");
    exit(0);
}
restore_assert(false, 'post-merge edits must block restoration.');
