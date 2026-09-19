<?php

namespace app\common\util;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use think\Db;

class TmdbReviewWorkspace
{
    private $clock;

    public function __construct(callable $clock = null)
    {
        $this->clock = $clock ?: 'time';
    }

    public function queue(int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $total = $this->countQueueRows();
        return [
            'rows' => $this->readQueueRows(($page - 1) * $perPage, $perPage),
            'total' => $total, 'page' => $page, 'per_page' => $perPage,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function preview(int $reviewId, callable $differenceBuilder): array
    {
        if ($reviewId <= 0) { throw new InvalidArgumentException('TMDB review ID must be positive.'); }
        $review = $this->loadReview($reviewId);
        if (!$review) { throw new RuntimeException('TMDB review was not found.'); }
        $json = (string) ($review['candidates_json'] ?? '');
        if ($json === '' || !hash_equals((string) ($review['candidates_hash'] ?? ''), hash('sha256', $json))) {
            throw new RuntimeException('TMDB candidates failed integrity verification.');
        }
        try { $candidates = json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $exception) { throw new RuntimeException('TMDB candidates are invalid.', 0, $exception); }
        if (!is_array($candidates)) { throw new RuntimeException('TMDB candidates are invalid.'); }
        $selected = null;
        $preferred = (int) ($review['preselected_tmdb_id'] ?? 0);
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) { throw new RuntimeException('TMDB candidate is invalid.'); }
            $this->assertCandidate($candidate);
            if ($selected === null || (int) $candidate['id'] === $preferred) { $selected = $candidate; }
        }
        return [
            'review' => $review,
            'candidates' => $candidates,
            'selected_candidate' => $selected,
            'differences' => $selected ? (array) $differenceBuilder((int) $review['vod_id'], $selected) : [],
        ];
    }

    public function select(int $reviewId, string $type, int $tmdbId, int $reviewerId): array
    {
        if ($reviewerId <= 0) { throw new InvalidArgumentException('Reviewer ID must be positive.'); }
        $type = strtolower(trim($type));
        $review = $this->loadReview($reviewId);
        if (!$review || (string) $review['status'] !== 'candidate_review') { throw new RuntimeException('TMDB review is no longer pending.'); }
        $preview = $this->preview($reviewId, static fn (): array => []);
        $found = false;
        foreach ($preview['candidates'] as $candidate) {
            if ((int) $candidate['id'] === $tmdbId && strtolower((string) $candidate['media_type']) === $type) { $found = true; break; }
        }
        if (!$found) { throw new InvalidArgumentException('Selected TMDB candidate is not stored in this review.'); }
        if (!$this->persistDecision($reviewId, 'matched', $type, $tmdbId, $reviewerId, (int) call_user_func($this->clock))) {
            throw new RuntimeException('TMDB selection conflicted with another review.');
        }
        return ['status' => 'matched', 'tmdb_type' => $type, 'tmdb_id' => $tmdbId];
    }

    public function noMatch(int $reviewId, int $reviewerId): array
    {
        if ($reviewerId <= 0) { throw new InvalidArgumentException('Reviewer ID must be positive.'); }
        if (!$this->persistDecision($reviewId, 'no_match', '', 0, $reviewerId, (int) call_user_func($this->clock))) {
            throw new RuntimeException('TMDB no-match decision conflicted with another review.');
        }
        return ['status' => 'no_match'];
    }

    public function enqueueManual(int $vodId, string $type, int $tmdbId, int $reviewerId): array
    {
        $type = strtolower(trim($type));
        if ($vodId <= 0 || $reviewerId <= 0 || $tmdbId <= 0 || !in_array($type, ['movie', 'tv'], true)) {
            throw new InvalidArgumentException('Manual TMDB request is invalid.');
        }
        $now = (int) call_user_func($this->clock);
        return $this->enqueueJob('tmdb_manual_match', ['vod_id' => $vodId, 'media_type' => $type, 'tmdb_id' => $tmdbId, 'requested_by' => $reviewerId], 'tmdb-manual:' . $vodId . ':' . $type . ':' . $tmdbId . ':' . $now);
    }

    public function enqueueRematch(int $vodId, int $reviewerId): array
    {
        if ($vodId <= 0 || $reviewerId <= 0) { throw new InvalidArgumentException('TMDB rematch request is invalid.'); }
        $now = (int) call_user_func($this->clock);
        return $this->enqueueJob('tmdb_match', ['vod_id' => $vodId, 'force' => true, 'requested_by' => $reviewerId], 'tmdb-rematch:' . $vodId . ':' . $now);
    }

    protected function readQueueRows(int $offset, int $limit): array
    {
        return Db::name('content_tmdb_review')->alias('r')
            ->join('__VOD_EXT__ e', 'e.vod_id=r.vod_id')
            ->join('__VOD__ v', 'v.vod_id=r.vod_id')
            ->field('r.*,e.public_id,e.workflow_status,v.vod_name,v.vod_year')
            ->order("r.status='candidate_review' DESC,r.updated_at ASC,r.tmdb_review_id ASC")
            ->limit($offset, $limit)->select();
    }

    protected function countQueueRows(): int { return (int) Db::name('content_tmdb_review')->count(); }
    protected function loadReview(int $reviewId): ?array
    {
        $row = Db::name('content_tmdb_review')->alias('r')->join('__VOD_EXT__ e', 'e.vod_id=r.vod_id')->join('__VOD__ v', 'v.vod_id=r.vod_id')
            ->field('r.*,e.public_id,e.workflow_status,v.vod_name,v.vod_year')->where('r.tmdb_review_id', $reviewId)->find();
        return $row ?: null;
    }
    protected function persistDecision(int $reviewId, string $status, string $type, int $tmdbId, int $reviewerId, int $now): bool
    {
        return Db::name('content_tmdb_review')->where(['tmdb_review_id' => $reviewId, 'status' => 'candidate_review'])->update([
            'status' => $status, 'selected_type' => $type, 'selected_tmdb_id' => $tmdbId,
            'reviewed_by' => $reviewerId, 'reviewed_at' => $now, 'updated_at' => $now,
        ]) === 1;
    }
    protected function enqueueJob(string $jobType, array $payload, string $key): array
    {
        return (new ContentJobRepository($this->clock))->enqueue($jobType, $payload, $key, 10, 3);
    }
    private function assertCandidate(array $candidate): void
    {
        $type = strtolower(trim((string) ($candidate['media_type'] ?? '')));
        if ((int) ($candidate['id'] ?? 0) <= 0 || !in_array($type, ['movie', 'tv'], true)) {
            throw new RuntimeException('TMDB candidate identity is invalid.');
        }
    }
}
