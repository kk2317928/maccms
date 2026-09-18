<?php

namespace app\common\util;

use InvalidArgumentException;
use JsonException;
use think\Db;

class DuplicateCandidateRepository
{
    private $clock;

    public function __construct(callable $clock = null)
    {
        $this->clock = $clock ?: 'time';
    }

    public function record(
        int $firstVodId,
        int $secondVodId,
        int $score,
        array $evidence,
        string $firstFingerprint = '',
        string $secondFingerprint = ''
    ): int
    {
        if ($firstVodId <= 0 || $secondVodId <= 0 || $firstVodId === $secondVodId) {
            throw new InvalidArgumentException('Duplicate candidates require two different positive video IDs.');
        }
        if ($score < 0 || $score > 1000) {
            throw new InvalidArgumentException('Duplicate score must be between zero and 1000.');
        }
        $firstFingerprint = $firstFingerprint === '' ? hash('sha256', '') : $firstFingerprint;
        $secondFingerprint = $secondFingerprint === '' ? hash('sha256', '') : $secondFingerprint;
        foreach ([$firstFingerprint, $secondFingerprint] as $fingerprint) {
            if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
                throw new InvalidArgumentException('Candidate fingerprint must be a SHA-256 hex digest.');
            }
        }
        foreach ($evidence as $reason) {
            if (!is_string($reason) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $reason)) {
                throw new InvalidArgumentException('Duplicate evidence contains an invalid reason.');
            }
        }
        try {
            $evidenceJson = json_encode(array_values(array_unique($evidence)), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Duplicate evidence must be JSON encodable.', 0, $exception);
        }
        $now = (int) call_user_func($this->clock);
        $ascending = $firstVodId < $secondVodId;
        return $this->persist([
            'vod_id_low' => $ascending ? $firstVodId : $secondVodId,
            'vod_id_high' => $ascending ? $secondVodId : $firstVodId,
            'fingerprint_low' => $ascending ? $firstFingerprint : $secondFingerprint,
            'fingerprint_high' => $ascending ? $secondFingerprint : $firstFingerprint,
            'evidence_json' => $evidenceJson,
            'score' => $score,
            'decision' => 'pending',
            'reviewed_by' => 0,
            'reviewed_at' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function persist(array $row): int
    {
        $existing = Db::name('content_duplicate_candidate')->where([
            'vod_id_low' => $row['vod_id_low'], 'vod_id_high' => $row['vod_id_high'],
        ])->find();
        if ($existing) {
            $row['created_at'] = (int) $existing['created_at'];
            Db::name('content_duplicate_candidate')
                ->where('duplicate_candidate_id', (int) $existing['duplicate_candidate_id'])
                ->update($row);
            return (int) $existing['duplicate_candidate_id'];
        }
        return (int) Db::name('content_duplicate_candidate')->insertGetId($row);
    }
}
