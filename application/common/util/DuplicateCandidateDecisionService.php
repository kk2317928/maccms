<?php

namespace app\common\util;

use InvalidArgumentException;
use RuntimeException;
use think\Db;
use Throwable;

class DuplicateCandidateDecisionService
{
    private $clock;

    public function __construct(callable $clock = null)
    {
        $this->clock = $clock ?: 'time';
    }

    public function markDifferent(int $candidateId, int $reviewerId, callable $beforeCommit = null): bool
    {
        $this->assertPositive($candidateId, 'Candidate ID');
        $this->assertPositive($reviewerId, 'Reviewer ID');
        $candidate = $this->loadCandidate($candidateId);
        if ($candidate === null) {
            return false;
        }
        if (($candidate['decision'] ?? '') === 'different') {
            return true;
        }
        $this->beginTransaction();
        try {
            $now = (int) call_user_func($this->clock);
            $updated = $this->updateCandidate($candidateId, [
                'decision' => 'different', 'reviewed_by' => $reviewerId,
                'reviewed_at' => $now, 'invalidated_at' => 0, 'updated_at' => $now,
            ], 'pending');
            if (!$updated) { throw new RuntimeException('Duplicate candidate decision changed.'); }
            if ($beforeCommit) { $beforeCommit(); }
            $this->commitTransaction();
            return true;
        } catch (Throwable $exception) {
            $this->rollbackTransaction();
            throw $exception;
        }
    }

    public function isPermanentlyDifferent(int $firstVodId, int $secondVodId): bool
    {
        if ($firstVodId <= 0 || $secondVodId <= 0 || $firstVodId === $secondVodId) {
            throw new InvalidArgumentException('Different-work lookup requires two different positive video IDs.');
        }
        $candidate = $this->loadPair(min($firstVodId, $secondVodId), max($firstVodId, $secondVodId));
        return $candidate !== null && ($candidate['decision'] ?? '') === 'different';
    }

    public function invalidateIfStale(int $candidateId, string $fingerprintLow, string $fingerprintHigh): bool
    {
        $this->assertPositive($candidateId, 'Candidate ID');
        $this->assertFingerprint($fingerprintLow);
        $this->assertFingerprint($fingerprintHigh);
        $candidate = $this->loadCandidate($candidateId);
        if ($candidate === null || ($candidate['decision'] ?? '') !== 'pending') {
            return false;
        }
        if (hash_equals((string) $candidate['fingerprint_low'], $fingerprintLow)
            && hash_equals((string) $candidate['fingerprint_high'], $fingerprintHigh)) {
            return false;
        }
        $now = (int) call_user_func($this->clock);
        return $this->updateCandidate($candidateId, [
            'decision' => 'invalidated',
            'invalidated_at' => $now,
            'updated_at' => $now,
        ], 'pending');
    }

    protected function loadCandidate(int $candidateId): ?array
    {
        $row = Db::name('content_duplicate_candidate')->where('duplicate_candidate_id', $candidateId)->find();
        return $row ?: null;
    }

    protected function loadPair(int $low, int $high): ?array
    {
        $row = Db::name('content_duplicate_candidate')->where([
            'vod_id_low' => $low, 'vod_id_high' => $high,
        ])->find();
        return $row ?: null;
    }

    protected function updateCandidate(int $candidateId, array $changes, string $expectedDecision): bool
    {
        return Db::name('content_duplicate_candidate')->where([
            'duplicate_candidate_id' => $candidateId, 'decision' => $expectedDecision,
        ])->update($changes) > 0;
    }
    protected function beginTransaction(): void { Db::startTrans(); }
    protected function commitTransaction(): void { Db::commit(); }
    protected function rollbackTransaction(): void { Db::rollback(); }

    private function assertPositive(int $value, string $label): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException($label . ' must be positive.');
        }
    }

    private function assertFingerprint(string $fingerprint): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            throw new InvalidArgumentException('Candidate fingerprint must be a SHA-256 hex digest.');
        }
    }
}
