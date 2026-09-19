<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$paths = ['TmdbReviewWorkspace.php', 'TmdbReviewJobHandler.php'];
foreach ($paths as $file) {
    $path = $root . '/application/common/util/' . $file;
    if (!is_file($path)) { fwrite(STDERR, "FAIL: {$file} is missing.\n"); exit(1); }
    require_once $path;
}

use app\common\util\TmdbReviewWorkspace;
use app\common\util\TmdbReviewJobHandler;

function tmdbUiAssert($condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
}

final class MemoryTmdbReviewWorkspace extends TmdbReviewWorkspace
{
    public $rows = [];
    public $jobs = [];
    public $recorded = [];
    protected function readQueueRows(int $offset, int $limit): array { return array_slice(array_values($this->rows), $offset, $limit); }
    protected function countQueueRows(): int { return count($this->rows); }
    protected function loadReview(int $reviewId): ?array { return $this->rows[$reviewId] ?? null; }
    protected function persistDecision(int $reviewId, string $status, string $type, int $tmdbId, int $reviewerId, int $now): bool
    {
        if (!isset($this->rows[$reviewId]) || $this->rows[$reviewId]['status'] !== 'candidate_review') { return false; }
        $this->rows[$reviewId] = array_merge($this->rows[$reviewId], ['status' => $status, 'selected_type' => $type, 'selected_tmdb_id' => $tmdbId, 'reviewed_by' => $reviewerId, 'reviewed_at' => $now]);
        return true;
    }
    protected function enqueueJob(string $jobType, array $payload, string $key): array { $this->jobs[] = compact('jobType', 'payload', 'key'); return ['job_id' => count($this->jobs)]; }
    protected function nextRevision(int $vodId): int { return count($this->recorded) + 1; }
    protected function persistReview(array $row): int { $this->recorded[] = $row; return count($this->recorded); }
    public function lockForUpdate(int $reviewId): array { return $this->rows[$reviewId]; }
}

$candidate = [
    'id' => 123, 'media_type' => 'movie', 'title_tw' => '新片名', 'title_en' => 'New Title',
    'original_title' => 'Original', 'year' => 2024, 'overview' => '摘要', 'regions' => ['JP'],
    'genres' => ['Drama'], 'actors' => ['Actor'], 'directors' => ['Director'], 'poster_url' => 'https://img.test/1.jpg',
    'score' => 910, 'evidence' => ['title', 'year'],
];
$json = json_encode([$candidate], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$service = new MemoryTmdbReviewWorkspace(static fn (): int => 1726800000);
$service->rows[7] = [
    'tmdb_review_id' => 7, 'vod_id' => 42, 'public_id' => 'ABC234', 'vod_name' => '舊片名',
    'workflow_status' => 'tmdb_matching', 'status' => 'candidate_review', 'candidates_json' => $json,
    'candidates_hash' => hash('sha256', $json), 'preselected_tmdb_id' => 123, 'selected_tmdb_id' => 0,
    'selected_type' => '', 'reviewed_by' => 0, 'reviewed_at' => 0, 'created_at' => 100, 'updated_at' => 100,
];

$queue = $service->queue(1, 20);
tmdbUiAssert($queue['total'] === 1 && $queue['rows'][0]['public_id'] === 'ABC234', 'queue must expose stable public identity and paging.');
$preview = $service->preview(7, static function (int $vodId, array $candidate): array {
    return ['title_tw' => ['current' => '舊片名', 'candidate' => $candidate['title_tw'], 'changed' => true, 'source' => 'manual', 'locked' => true]];
});
tmdbUiAssert($preview['candidates'][0]['id'] === 123 && $preview['differences']['title_tw']['locked'] === true, 'preview must verify candidates and expose field differences and locks.');
$candidateB = array_merge($candidate, ['id' => 124, 'title_tw' => '另一候選']);
$jsonB = json_encode([$candidate, $candidateB], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$service->rows[10] = array_merge($service->rows[7], ['tmdb_review_id' => 10, 'candidates_json' => $jsonB, 'candidates_hash' => hash('sha256', $jsonB)]);
$chosen = $service->preview(10, static fn (int $vodId, array $item): array => ['title_tw' => ['current' => '舊片名', 'candidate' => $item['title_tw'], 'changed' => true, 'source' => 'manual', 'locked' => false]], 'movie', 124);
tmdbUiAssert($chosen['selected_candidate']['id'] === 124 && $chosen['differences']['title_tw']['candidate'] === '另一候選', 'field differences must be bound to the explicitly selected candidate.');
$selected = $service->select(7, 'movie', 123, 55);
tmdbUiAssert($selected['status'] === 'matched' && $service->rows[7]['selected_tmdb_id'] === 123, 'selection must only accept a stored candidate.');

$service->rows[8] = array_merge($service->rows[7], ['tmdb_review_id' => 8, 'status' => 'candidate_review', 'selected_tmdb_id' => 0]);
tmdbUiAssert($service->noMatch(8, 55)['status'] === 'no_match', 'no-match must be an explicit reviewed decision.');
$manual = $service->enqueueManual(42, 'tv', 777, 55);
$rematch = $service->enqueueRematch(42, 55);
tmdbUiAssert($manual['job_id'] === 1 && $rematch['job_id'] === 2 && count($service->jobs) === 2, 'manual ID and rematch must enqueue traceable jobs rather than call TMDB inline.');
$recorded = $service->recordResult(42, ['status' => 'candidate_review', 'candidates' => [$candidate], 'preselected_id' => 123]);
tmdbUiAssert($recorded['review_id'] === 1 && $service->recorded[0]['candidates_hash'] === hash('sha256', $service->recorded[0]['candidates_json']), 'worker results must persist an integrity-checked review revision.');

$handlerWorkspace = new MemoryTmdbReviewWorkspace(static fn (): int => 1726800000);
$handler = new TmdbReviewJobHandler(
    $handlerWorkspace,
    static fn (int $vodId): array => ['original_title' => 'Original', 'year' => 2024, 'media_type' => 'movie'],
    static fn (array $video): array => ['status' => 'candidate_review', 'candidates' => [$GLOBALS['candidate']], 'preselected_id' => 123],
    static fn (string $type, int $id): array => ['status' => 'candidate_review', 'candidates' => [array_merge($GLOBALS['candidate'], ['id' => $id, 'media_type' => $type])], 'preselected_id' => $id, 'manual' => true],
    static fn (callable $callback) => $callback()
);
$handler->match(['vod_id' => 42]);
$handler->manual(['vod_id' => 42, 'media_type' => 'tv', 'tmdb_id' => 777]);
tmdbUiAssert(count($handlerWorkspace->recorded) === 2 && $handlerWorkspace->recorded[1]['preselected_tmdb_id'] === 777, 'registered handlers must consume queued match/manual jobs and persist reviews.');

$service->rows[9] = array_merge($service->rows[7], ['tmdb_review_id' => 9, 'status' => 'candidate_review', 'candidates_hash' => str_repeat('0', 64)]);
try { $service->preview(9, static fn (): array => []); tmdbUiAssert(false, 'tampered candidates were previewed.'); } catch (RuntimeException $exception) {}

$migration = @file_get_contents($root . '/application/data/migrations/20260919000300_tmdb_reviews.sql') ?: '';
foreach (['content_tmdb_review', 'candidates_json', 'candidates_hash', 'uk_vod_revision'] as $needle) {
    tmdbUiAssert(strpos($migration, $needle) !== false, 'TMDB review migration missing ' . $needle . '.');
}
$controller = @file_get_contents($root . '/application/admin/controller/ContentWorkspace.php') ?: '';
$template = @file_get_contents($root . '/application/admin/view_new/content_workspace/tmdb_review.html') ?: '';
foreach (['function tmdb_review', "assertAllowed('review'", "assertAllowed('run_tmdb'", 'mac_admin_csrf_token', 'enqueueManual', 'enqueueRematch', 'noMatch'] as $needle) {
    tmdbUiAssert(strpos($controller, $needle) !== false, 'TMDB controller contract missing ' . $needle . '.');
}
foreach (['|htmlentities', '候選', '手動 TMDB ID', '無匹配', '重新配對', '欄位差異', 'is_locked', 'data-action="select"', 'data-action="manual"', 'data-action="no_match"', 'data-action="rematch"', '{foreach name="preview.differences"', "'candidate_type'", "'candidate_id'"] as $needle) {
    tmdbUiAssert(strpos($template, $needle) !== false, 'TMDB review view contract missing ' . $needle . '.');
}
$dashboard = @file_get_contents($root . '/application/admin/view_new/content_workspace/index.html') ?: '';
tmdbUiAssert(strpos($dashboard, 'content_workspace/tmdb_review') !== false, 'workspace dashboard must link to TMDB review.');
$workspaceSource = @file_get_contents($root . '/application/common/util/TmdbReviewWorkspace.php') ?: '';
tmdbUiAssert(strpos($workspaceSource, 'orderRaw(') !== false, 'pending-first queue ordering must use ThinkPHP raw-order support.');
tmdbUiAssert(strpos($workspaceSource, 'lockForUpdate') !== false && strpos($workspaceSource, 'lock(true)') !== false, 'selection must lock review, video and governance rows before rechecking.');
$base = @file_get_contents($root . '/application/admin/controller/Base.php') ?: '';
tmdbUiAssert(strpos($base, "\$a === 'tmdb_review'") !== false && strpos($base, 'content_workspace/review') !== false && strpos($base, 'content_workspace/run_tmdb') !== false, 'native route authorization must admit exact TMDB review permissions for non-superadmins.');
$command = @file_get_contents($root . '/application/command/MaccmsJobs.php') ?: '';
tmdbUiAssert(strpos($command, "\$handlers['tmdb_match']") !== false && strpos($command, "\$handlers['tmdb_manual_match']") !== false, 'Cron worker must register both TMDB review job handlers.');

fwrite(STDOUT, "OK: TMDB candidate, manual match, no-match, rematch and field-difference UI contract passed.\n");
