<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$migration = @file_get_contents($root . '/application/data/migrations/20260918000600_duplicate_candidate_decisions.sql') ?: '';
foreach ([
    'ALTER TABLE `__PREFIX__content_duplicate_candidate`',
    '`fingerprint_low` char(64)',
    '`fingerprint_high` char(64)',
    '`invalidated_at` int(10) unsigned',
] as $needle) {
    if (strpos($migration, $needle) === false) { fwrite(STDERR, "FAIL: decision migration missing {$needle}\n"); exit(1); }
}

foreach (['DuplicateCandidateRepository.php', 'DuplicateCandidateDecisionService.php'] as $file) {
    $path = $root . '/application/common/util/' . $file;
    if (!is_file($path)) { fwrite(STDERR, "FAIL: {$file} is missing.\n"); exit(1); }
    require_once $path;
}

use app\common\util\DuplicateCandidateDecisionService;
use app\common\util\DuplicateCandidateRepository;

final class VersionedMemoryCandidateRepository extends DuplicateCandidateRepository
{
    public $rows = [];
    protected function persist(array $row): int { $row['duplicate_candidate_id'] = count($this->rows) + 1; $this->rows[] = $row; return $row['duplicate_candidate_id']; }
}

$leftFingerprint = hash('sha256', 'left-v1');
$rightFingerprint = hash('sha256', 'right-v1');
$repo = new VersionedMemoryCandidateRepository(static fn (): int => 1726704000);
$repo->record(42, 7, 750, ['multilingual_title_year'], $leftFingerprint, $rightFingerprint);
$versioned = $repo->rows[0];
if ($versioned['vod_id_low'] !== 7 || $versioned['fingerprint_low'] !== $rightFingerprint || $versioned['fingerprint_high'] !== $leftFingerprint) {
    fwrite(STDERR, "FAIL: fingerprints must follow the canonical candidate ID order.\n"); exit(1);
}
try { $repo->record(7, 42, 750, [], 'bad', $rightFingerprint); } catch (InvalidArgumentException $exception) {}
if (count($repo->rows) !== 1) { fwrite(STDERR, "FAIL: malformed fingerprints must be rejected.\n"); exit(1); }

final class MemoryCandidateDecisionService extends DuplicateCandidateDecisionService
{
    public $rows;
    private $transactionState;
    public function __construct(array $rows, callable $clock) { parent::__construct($clock); $this->rows = $rows; }
    protected function loadCandidate(int $candidateId): ?array { return $this->rows[$candidateId] ?? null; }
    protected function loadPair(int $low, int $high): ?array { foreach ($this->rows as $row) { if ($row['vod_id_low'] === $low && $row['vod_id_high'] === $high) return $row; } return null; }
    protected function updateCandidate(int $candidateId, array $changes, string $expectedDecision): bool { if (($this->rows[$candidateId]['decision'] ?? '') !== $expectedDecision) return false; $this->rows[$candidateId] = array_merge($this->rows[$candidateId], $changes); return true; }
    protected function beginTransaction(): void { $this->transactionState = $this->rows; }
    protected function commitTransaction(): void { $this->transactionState = null; }
    protected function rollbackTransaction(): void { $this->rows = $this->transactionState; }
}

$rows = [
    1 => ['duplicate_candidate_id' => 1, 'vod_id_low' => 7, 'vod_id_high' => 42, 'fingerprint_low' => $rightFingerprint, 'fingerprint_high' => $leftFingerprint, 'decision' => 'pending', 'reviewed_by' => 0, 'reviewed_at' => 0, 'invalidated_at' => 0],
    2 => ['duplicate_candidate_id' => 2, 'vod_id_low' => 8, 'vod_id_high' => 42, 'fingerprint_low' => hash('sha256', 'other-v1'), 'fingerprint_high' => $leftFingerprint, 'decision' => 'pending', 'reviewed_by' => 0, 'reviewed_at' => 0, 'invalidated_at' => 0],
];
$service = new MemoryCandidateDecisionService($rows, static fn (): int => 1726704100);
if (!$service->markDifferent(1, 99) || !$service->isPermanentlyDifferent(42, 7)) { fwrite(STDERR, "FAIL: reviewed different-work decision was not persisted.\n"); exit(1); }
$different = $service->rows[1];
if ($different['decision'] !== 'different' || $different['reviewed_by'] !== 99 || $different['reviewed_at'] !== 1726704100) { fwrite(STDERR, "FAIL: different-work review metadata is incomplete.\n"); exit(1); }
$auditFailing = new MemoryCandidateDecisionService($rows, static fn (): int => 1726704100);
try { $auditFailing->markDifferent(1, 99, static function (): void { throw new RuntimeException('audit failed'); }); } catch (RuntimeException $exception) {}
if ($auditFailing->rows !== $rows) { fwrite(STDERR, "FAIL: failed different-work audit must roll back the decision.\n"); exit(1); }
if ($service->invalidateIfStale(1, hash('sha256', 'right-v2'), $leftFingerprint) || $service->rows[1]['decision'] !== 'different') { fwrite(STDERR, "FAIL: permanent different-work decisions must survive content changes.\n"); exit(1); }
if (!$service->invalidateIfStale(2, hash('sha256', 'other-v1'), hash('sha256', 'left-v2'))) { fwrite(STDERR, "FAIL: changed content fingerprint must invalidate a pending candidate.\n"); exit(1); }
if ($service->rows[2]['decision'] !== 'invalidated' || $service->rows[2]['invalidated_at'] !== 1726704100) { fwrite(STDERR, "FAIL: stale candidate invalidation metadata is incomplete.\n"); exit(1); }
try { $service->markDifferent(2, 0); } catch (InvalidArgumentException $exception) { fwrite(STDOUT, "OK: permanent different-work and stale invalidation contract passed.\n"); exit(0); }
fwrite(STDERR, "FAIL: reviewer ID must be validated.\n"); exit(1);
