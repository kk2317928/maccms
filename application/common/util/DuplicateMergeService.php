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
    private $workflowCoordinator;

    public function __construct(callable $clock = null, $workflowCoordinator = null)
    {
        $this->clock = $clock ?: 'time';
        $this->workflowCoordinator = $workflowCoordinator;
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
            $now = (int) call_user_func($this->clock);

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
            $this->mergeRelatedData($primaryVodId, $secondaryVodId, $primary, $secondary);
            $this->markSecondaryMerged($secondaryVodId, $primaryVodId, $now);
            $snapshotPayload = [
                'version' => 2,
                'primary' => $primary,
                'secondary' => $secondary,
                'merged_projection' => [
                    'primary' => $this->loadBundle($primaryVodId),
                    'secondary' => $this->loadBundle($secondaryVodId),
                ],
            ];
            try {
                $snapshotJson = json_encode($snapshotPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Merge snapshot is not JSON encodable.', 0, $exception);
            }
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
            $this->markCandidateMerged($candidateId, $reviewerId, $now);
            if ($beforeCommit) { $beforeCommit(); }
            $this->commitTransaction();
            if ($this->workflowCoordinator !== null) { $this->workflowCoordinator->completeDuplicateReview($primaryVodId); }
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

    protected function mergeRelatedData(int $primaryVodId, int $secondaryVodId, array $primary, array $secondary): void
    {
        $aliases = [];
        foreach ([$primary['ext']['old_titles_json'] ?? '', $secondary['ext']['old_titles_json'] ?? ''] as $json) {
            $decoded = json_decode((string) $json, true);
            if (is_array($decoded)) { $aliases = array_merge($aliases, $decoded); }
        }
        foreach (['vod_name', 'vod_en'] as $field) { $aliases[] = $secondary['vod'][$field] ?? ''; }
        foreach (['title_tw', 'title_cn', 'title_en', 'original_title'] as $field) { $aliases[] = $secondary['ext'][$field] ?? ''; }
        $aliases = array_values(array_unique(array_filter(array_map('trim', $aliases), static function ($value) { return $value !== ''; })));
        if (Db::name('vod_ext')->where('vod_id', $primaryVodId)->update([
            'old_titles_json' => json_encode($aliases, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]) === false) { throw new RuntimeException('Primary title aliases could not be merged.'); }

        $this->mergeRows('vod_meta_term', 'term_id', 'vod_id', $primaryVodId, $secondaryVodId, $primary['meta_terms'], $secondary['meta_terms'], false);
        $this->mergeRows('vod_field_state', 'field_name', 'vod_id', $primaryVodId, $secondaryVodId, $primary['field_states'], $secondary['field_states'], false);
        $this->mergeRows('content_lang', 'lang_code', 'content_id', $primaryVodId, $secondaryVodId, $primary['content_lang'], $secondary['content_lang'], false, ['content_type' => 'vod']);
        // Provider identity conflicts stay attached to the secondary record for manual review.
        $this->mergeRows('ext_source_map', 'provider_code', 'cms_id', $primaryVodId, $secondaryVodId, $primary['external_maps'], $secondary['external_maps'], true, ['cms_mid' => 1]);
    }

    private function mergeRows(string $table, string $identity, string $owner, int $primaryId, int $secondaryId, array $primaryRows, array $secondaryRows, bool $keepConflicts, array $scope = []): void
    {
        $known = [];
        foreach ($primaryRows as $row) { $known[(string) ($row[$identity] ?? '')] = true; }
        foreach ($secondaryRows as $row) {
            $key = (string) ($row[$identity] ?? '');
            if ($key === '' || isset($known[$key])) {
                if (!$keepConflicts) { Db::name($table)->where($scope)->where($owner, $secondaryId)->where($identity, $row[$identity] ?? '')->delete(); }
                continue;
            }
            if (Db::name($table)->where($scope)->where($owner, $secondaryId)->where($identity, $row[$identity])->update([$owner => $primaryId]) === false) {
                throw new RuntimeException('Related duplicate data could not be merged.');
            }
            $known[$key] = true;
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
