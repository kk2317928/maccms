<?php

namespace app\common\util;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use think\Db;

class DuplicateReviewWorkspace
{
    public function queue(int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        return [
            'total' => $this->countQueueRows(),
            'rows' => $this->readQueueRows(($page - 1) * $limit, $limit),
            'page' => $page, 'limit' => $limit,
        ];
    }

    public function compare(int $candidateId): array
    {
        if ($candidateId < 1) { throw new InvalidArgumentException('Duplicate candidate ID must be positive.'); }
        $candidate = $this->loadCandidate($candidateId);
        if (!$candidate) { throw new RuntimeException('Duplicate candidate was not found.'); }
        $left = $this->loadVideo((int) $candidate['vod_id_low']);
        $right = $this->loadVideo((int) $candidate['vod_id_high']);
        if (!$left || !$right) { throw new RuntimeException('Duplicate comparison video is incomplete.'); }
        $this->assertPublicId((string) ($left['ext']['public_id'] ?? ''));
        $this->assertPublicId((string) ($right['ext']['public_id'] ?? ''));
        try { $evidence = json_decode((string) ($candidate['evidence_json'] ?? '[]'), true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException $exception) { throw new RuntimeException('Duplicate evidence is invalid.', 0, $exception); }
        if (!is_array($evidence) || !array_is_list($evidence)) { throw new RuntimeException('Duplicate evidence is invalid.'); }
        return ['candidate' => $candidate, 'evidence' => $evidence, 'left' => $left, 'right' => $right, 'snapshot' => $this->loadSnapshotByCandidate($candidateId)];
    }

    public function snapshotPreview(int $snapshotId): array
    {
        if ($snapshotId < 1) { throw new InvalidArgumentException('Merge snapshot ID must be positive.'); }
        $snapshot = $this->loadSnapshot($snapshotId);
        if (!$snapshot) { throw new RuntimeException('Merge snapshot was not found.'); }
        $json = (string) ($snapshot['snapshot_json'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', (string) ($snapshot['snapshot_hash'] ?? ''))
            || !hash_equals((string) $snapshot['snapshot_hash'], hash('sha256', $json))) {
            throw new RuntimeException('Merge snapshot integrity check failed.');
        }
        try { $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $exception) { throw new RuntimeException('Merge snapshot is invalid.', 0, $exception); }
        if (($payload['version'] ?? null) !== 1 || !is_array($payload['primary'] ?? null) || !is_array($payload['secondary'] ?? null)) {
            throw new RuntimeException('Merge snapshot format is unsupported.');
        }
        $this->assertPublicId((string) ($payload['primary']['ext']['public_id'] ?? ''));
        $this->assertPublicId((string) ($payload['secondary']['ext']['public_id'] ?? ''));
        return ['snapshot' => $snapshot, 'payload' => $payload];
    }

    protected function countQueueRows(): int
    {
        return (int) Db::name('content_duplicate_candidate')->where('decision', 'in', ['pending', 'merged'])->count();
    }

    protected function readQueueRows(int $offset, int $limit): array
    {
        $rows = Db::name('content_duplicate_candidate')->alias('c')
            ->join($this->tablePrefix() . 'content_merge_snapshot s', 's.duplicate_candidate_id=c.duplicate_candidate_id', 'left')
            ->where('c.decision', 'in', ['pending', 'merged'])
            ->field('c.*,s.merge_snapshot_id,s.status AS snapshot_status')
            ->order("c.decision='pending' DESC,c.score desc,c.updated_at asc")->limit($offset, $limit)->select();
        return $rows ?: [];
    }

    protected function loadCandidate(int $candidateId): ?array
    {
        $row = Db::name('content_duplicate_candidate')->where('duplicate_candidate_id', $candidateId)->find();
        return $row ?: null;
    }

    protected function loadVideo(int $vodId): ?array
    {
        $vod = Db::name('vod')->where('vod_id', $vodId)->find();
        $ext = Db::name('vod_ext')->where('vod_id', $vodId)->find();
        if (!$vod || !$ext) { return null; }
        return [
            'vod' => $vod, 'ext' => $ext,
            'content_lang' => $this->normalizeLanguageRows(Db::name('content_lang')->where(['content_type' => 'vod', 'content_id' => $vodId])->order('lang_code asc')->select() ?: []),
            'external_maps' => Db::name('ext_source_map')->where(['cms_mid' => 1, 'cms_id' => $vodId])->order('provider_code asc')->select() ?: [],
            'field_states' => Db::name('vod_field_state')->where('vod_id', $vodId)->order('field_name asc')->select() ?: [],
        ];
    }

    protected function loadSnapshotByCandidate(int $candidateId): ?array
    {
        $row = Db::name('content_merge_snapshot')->where('duplicate_candidate_id', $candidateId)->find();
        return $row ?: null;
    }

    protected function loadSnapshot(int $snapshotId): ?array
    {
        $row = Db::name('content_merge_snapshot')->where('merge_snapshot_id', $snapshotId)->find();
        return $row ?: null;
    }

    private function tablePrefix(): string
    {
        $prefix = (string) config('database.prefix');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) { throw new RuntimeException('Invalid database table prefix.'); }
        return $prefix;
    }
    private function assertPublicId(string $publicId): void
    {
        if (!preg_match('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}$/', $publicId)) {
            throw new RuntimeException('Duplicate video public identity is invalid.');
        }
    }
    private function normalizeLanguageRows(array $rows): array
    {
        foreach ($rows as &$row) {
            try { $data = json_decode((string) ($row['data'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR); }
            catch (JsonException $exception) { $data = []; }
            $row['fields'] = is_array($data) ? $data : [];
        }
        unset($row);
        return $rows;
    }
}
