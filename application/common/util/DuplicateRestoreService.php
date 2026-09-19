<?php

namespace app\common\util;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use think\Db;
use Throwable;

class DuplicateRestoreService
{
    private $clock;

    public function __construct(callable $clock = null)
    {
        $this->clock = $clock ?: 'time';
    }

    public function restore(
        int $snapshotId,
        int $reviewerId,
        bool $confirmed,
        callable $authorize,
        callable $beforeCommit = null
    ): void {
        if ($snapshotId <= 0 || $reviewerId <= 0) {
            throw new InvalidArgumentException('Restore IDs must be positive.');
        }
        if (!$confirmed) {
            throw new RuntimeException('Duplicate restoration requires explicit confirmation.');
        }
        if ($authorize($reviewerId, 'content_duplicate_restore') !== true) {
            throw new RuntimeException('Reviewer is not authorized to restore duplicate merges.');
        }

        $this->beginTransaction();
        try {
            $snapshot = $this->loadSnapshot($snapshotId);
            if ($snapshot === null || ($snapshot['status'] ?? '') !== 'active') {
                throw new RuntimeException('Merge snapshot is not active.');
            }
            $snapshotJson = (string) ($snapshot['snapshot_json'] ?? '');
            if (!hash_equals((string) ($snapshot['snapshot_hash'] ?? ''), hash('sha256', $snapshotJson))) {
                throw new RuntimeException('Merge snapshot integrity check failed.');
            }
            try {
                $payload = json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Merge snapshot is not valid JSON.', 0, $exception);
            }
            if (($payload['version'] ?? null) !== 1 || !is_array($payload['primary'] ?? null) || !is_array($payload['secondary'] ?? null)) {
                throw new RuntimeException('Merge snapshot format is unsupported.');
            }

            $candidateId = (int) ($snapshot['duplicate_candidate_id'] ?? 0);
            $primaryVodId = (int) ($snapshot['primary_vod_id'] ?? 0);
            $secondaryVodId = (int) ($snapshot['secondary_vod_id'] ?? 0);
            $candidate = $this->loadCandidate($candidateId);
            if (!$this->candidateMatchesMerge($candidate, $snapshot)) {
                throw new RuntimeException('Restoration conflict: duplicate decision changed after merge.');
            }

            [$expectedPrimary, $expectedSecondary] = $this->expectedMergedBundles($payload, $snapshot);
            if (!$this->bundlesEqual($this->loadBundle($primaryVodId), $expectedPrimary)
                || !$this->bundlesEqual($this->loadBundle($secondaryVodId), $expectedSecondary)) {
                throw new RuntimeException('Restoration conflict: video data changed after merge.');
            }

            $this->restoreBundle($primaryVodId, $payload['primary']);
            $this->restoreBundle($secondaryVodId, $payload['secondary']);
            $now = (int) call_user_func($this->clock);
            $this->reopenCandidate($candidateId, $now);
            $this->markSnapshotRestored($snapshotId, $reviewerId, $now);
            if ($beforeCommit) { $beforeCommit(); }
            $this->commitTransaction();
        } catch (Throwable $exception) {
            $this->rollbackTransaction();
            throw $exception;
        }
    }

    private function candidateMatchesMerge(?array $candidate, array $snapshot): bool
    {
        return is_array($candidate)
            && ($candidate['decision'] ?? '') === 'merged'
            && (int) ($candidate['reviewed_by'] ?? 0) === (int) ($snapshot['merged_by'] ?? 0)
            && (int) ($candidate['reviewed_at'] ?? 0) === (int) ($snapshot['merged_at'] ?? 0);
    }

    private function expectedMergedBundles(array $payload, array $snapshot): array
    {
        $primary = $payload['primary'];
        $secondary = $payload['secondary'];
        $primaryPlayback = VodPlaybackCodec::decode(
            (string) ($primary['vod']['vod_play_from'] ?? ''),
            (string) ($primary['vod']['vod_play_url'] ?? ''),
            (string) ($primary['vod']['vod_play_server'] ?? ''),
            (string) ($primary['vod']['vod_play_note'] ?? '')
        );
        $secondaryPlayback = VodPlaybackCodec::decode(
            (string) ($secondary['vod']['vod_play_from'] ?? ''),
            (string) ($secondary['vod']['vod_play_url'] ?? ''),
            (string) ($secondary['vod']['vod_play_server'] ?? ''),
            (string) ($secondary['vod']['vod_play_note'] ?? '')
        );
        $encoded = VodPlaybackCodec::encode(VodPlaybackCodec::merge($primaryPlayback, $secondaryPlayback));
        $primary['vod'] = array_merge($primary['vod'], [
            'vod_play_from' => $encoded['from'], 'vod_play_url' => $encoded['url'],
            'vod_play_server' => $encoded['server'], 'vod_play_note' => $encoded['note'],
        ]);
        $secondary['ext'] = array_merge($secondary['ext'], [
            'workflow_status' => VodWorkflow::MERGED,
            'merged_into_vod_id' => (int) $snapshot['primary_vod_id'],
            'updated_at' => (int) $snapshot['merged_at'],
        ]);
        return [$primary, $secondary];
    }

    private function bundlesEqual(array $actual, array $expected): bool
    {
        return $this->normalize($actual) === $this->normalize($expected);
    }

    private function normalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
            foreach ($value as $key => $item) { $value[$key] = $this->normalize($item); }
            return $value;
        }
        $value = array_map([$this, 'normalize'], $value);
        usort($value, static function ($left, $right) { return strcmp(serialize($left), serialize($right)); });
        return $value;
    }

    protected function beginTransaction(): void { Db::startTrans(); }
    protected function commitTransaction(): void { Db::commit(); }
    protected function rollbackTransaction(): void { Db::rollback(); }

    protected function loadSnapshot(int $snapshotId): ?array
    {
        $row = Db::name('content_merge_snapshot')->where('merge_snapshot_id', $snapshotId)->lock(true)->find();
        return $row ?: null;
    }

    protected function loadCandidate(int $candidateId): ?array
    {
        $row = Db::name('content_duplicate_candidate')->where('duplicate_candidate_id', $candidateId)->lock(true)->find();
        return $row ?: null;
    }

    protected function loadBundle(int $vodId): array
    {
        $vod = Db::name('vod')->where('vod_id', $vodId)->lock(true)->find();
        $ext = Db::name('vod_ext')->where('vod_id', $vodId)->lock(true)->find();
        if (!$vod || !$ext) { throw new RuntimeException('Restore video bundle is incomplete.'); }
        return [
            'vod' => $vod, 'ext' => $ext,
            'meta_terms' => Db::name('vod_meta_term')->where('vod_id', $vodId)->select(),
            'field_states' => Db::name('vod_field_state')->where('vod_id', $vodId)->select(),
            'external_maps' => Db::name('ext_source_map')->where(['cms_mid' => 1, 'cms_id' => $vodId])->select(),
            'content_lang' => Db::name('content_lang')->where(['content_type' => 'vod', 'content_id' => $vodId])->select(),
            'ulog' => Db::name('ulog')->where(['ulog_mid' => 1, 'ulog_rid' => $vodId])->select(),
        ];
    }

    protected function restoreBundle(int $vodId, array $bundle): void
    {
        if (Db::name('vod')->where('vod_id', $vodId)->update($bundle['vod']) === false
            || Db::name('vod_ext')->where('vod_id', $vodId)->update($bundle['ext']) === false) {
            throw new RuntimeException('Snapshotted video rows could not be restored.');
        }
    }

    protected function reopenCandidate(int $candidateId, int $now): void
    {
        if (Db::name('content_duplicate_candidate')->where(['duplicate_candidate_id' => $candidateId, 'decision' => 'merged'])->update([
            'decision' => 'pending', 'reviewed_by' => 0, 'reviewed_at' => 0, 'updated_at' => $now,
        ]) < 1) { throw new RuntimeException('Duplicate candidate could not be reopened.'); }
    }

    protected function markSnapshotRestored(int $snapshotId, int $reviewerId, int $now): void
    {
        if (Db::name('content_merge_snapshot')->where(['merge_snapshot_id' => $snapshotId, 'status' => 'active'])->update([
            'status' => 'restored', 'restored_by' => $reviewerId, 'restored_at' => $now,
        ]) < 1) { throw new RuntimeException('Restoration audit record could not be saved.'); }
    }
}
