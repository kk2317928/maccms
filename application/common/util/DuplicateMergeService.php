<?php

namespace app\common\util;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use think\Db;
use Throwable;

class DuplicateMergeService
{
    private $clock;

    public function __construct(callable $clock = null)
    {
        $this->clock = $clock ?: 'time';
    }

    public function merge(int $candidateId, int $primaryVodId, int $secondaryVodId, int $reviewerId, callable $beforeCommit = null): int
    {
        foreach ([$candidateId, $primaryVodId, $secondaryVodId, $reviewerId] as $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException('Merge IDs must be positive.');
            }
        }
        if ($primaryVodId === $secondaryVodId) {
            throw new InvalidArgumentException('Primary and secondary videos must differ.');
        }

        $this->beginTransaction();
        try {
            $candidate = $this->loadCandidate($candidateId);
            if ($candidate === null || ($candidate['decision'] ?? '') !== 'pending') {
                throw new RuntimeException('Duplicate candidate is not pending review.');
            }
            $expected = [(int) $candidate['vod_id_low'], (int) $candidate['vod_id_high']];
            sort($expected);
            $selected = [$primaryVodId, $secondaryVodId];
            sort($selected);
            if ($expected !== $selected) {
                throw new InvalidArgumentException('Selected videos do not match the duplicate candidate.');
            }

            $primary = $this->loadBundle($primaryVodId);
            $secondary = $this->loadBundle($secondaryVodId);
            $snapshotPayload = ['version' => 1, 'primary' => $primary, 'secondary' => $secondary];
            try {
                $snapshotJson = json_encode($snapshotPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Merge snapshot is not JSON encodable.', 0, $exception);
            }
            $now = (int) call_user_func($this->clock);
            $snapshotId = $this->insertSnapshot([
                'duplicate_candidate_id' => $candidateId,
                'primary_vod_id' => $primaryVodId,
                'secondary_vod_id' => $secondaryVodId,
                'snapshot_json' => $snapshotJson,
                'snapshot_hash' => hash('sha256', $snapshotJson),
                'status' => 'active',
                'merged_by' => $reviewerId,
                'merged_at' => $now,
                'restored_by' => 0,
                'restored_at' => 0,
            ]);

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
            $this->updatePrimaryPlayback($primaryVodId, [
                'vod_play_from' => $encoded['from'],
                'vod_play_url' => $encoded['url'],
                'vod_play_server' => $encoded['server'],
                'vod_play_note' => $encoded['note'],
            ]);
            $this->markSecondaryMerged($secondaryVodId, $primaryVodId, $now);
            $this->markCandidateMerged($candidateId, $reviewerId, $now);
            if ($beforeCommit) { $beforeCommit(); }
            $this->commitTransaction();
            return $snapshotId;
        } catch (Throwable $exception) {
            $this->rollbackTransaction();
            throw $exception;
        }
    }

    protected function beginTransaction(): void { Db::startTrans(); }
    protected function commitTransaction(): void { Db::commit(); }
    protected function rollbackTransaction(): void { Db::rollback(); }

    protected function loadCandidate(int $candidateId): ?array
    {
        $row = Db::name('content_duplicate_candidate')->where('duplicate_candidate_id', $candidateId)->lock(true)->find();
        return $row ?: null;
    }

    protected function loadBundle(int $vodId): array
    {
        $vod = Db::name('vod')->where('vod_id', $vodId)->lock(true)->find();
        $ext = Db::name('vod_ext')->where('vod_id', $vodId)->lock(true)->find();
        if (!$vod || !$ext) {
            throw new RuntimeException('Merge video bundle is incomplete.');
        }
        return [
            'vod' => $vod,
            'ext' => $ext,
            'meta_terms' => Db::name('vod_meta_term')->where('vod_id', $vodId)->select(),
            'field_states' => Db::name('vod_field_state')->where('vod_id', $vodId)->select(),
            'external_maps' => Db::name('ext_source_map')->where(['cms_mid' => 1, 'cms_id' => $vodId])->select(),
            'content_lang' => Db::name('content_lang')->where(['content_type' => 'vod', 'content_id' => $vodId])->select(),
            'ulog' => Db::name('ulog')->where(['ulog_mid' => 1, 'ulog_rid' => $vodId])->select(),
        ];
    }

    protected function insertSnapshot(array $row): int
    {
        return (int) Db::name('content_merge_snapshot')->insertGetId($row);
    }

    protected function updatePrimaryPlayback(int $vodId, array $playback): void
    {
        if (Db::name('vod')->where('vod_id', $vodId)->update($playback) === false) {
            throw new RuntimeException('Primary playback could not be updated.');
        }
    }

    protected function markSecondaryMerged(int $secondaryVodId, int $primaryVodId, int $now): void
    {
        if (Db::name('vod_ext')->where('vod_id', $secondaryVodId)->update([
            'workflow_status' => VodWorkflow::MERGED,
            'merged_into_vod_id' => $primaryVodId,
            'updated_at' => $now,
        ]) < 1) {
            throw new RuntimeException('Secondary video could not be marked merged.');
        }
    }

    protected function markCandidateMerged(int $candidateId, int $reviewerId, int $now): void
    {
        if (Db::name('content_duplicate_candidate')->where([
            'duplicate_candidate_id' => $candidateId, 'decision' => 'pending',
        ])->update([
            'decision' => 'merged', 'reviewed_by' => $reviewerId,
            'reviewed_at' => $now, 'updated_at' => $now,
        ]) < 1) {
            throw new RuntimeException('Duplicate candidate merge decision could not be saved.');
        }
    }
}
