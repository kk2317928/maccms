<?php

namespace app\common\util;

use InvalidArgumentException;
use RuntimeException;

class TmdbMatchService
{
    public function buildQueries(array $video): array
    {
        $titles = [
            $video['original_title'] ?? '', $video['title_en'] ?? '',
            $video['title_tw'] ?? '', $video['title_cn'] ?? '',
        ];
        foreach (($video['aliases'] ?? []) as $alias) { $titles[] = $alias; }
        $queries = [];
        $seen = [];
        foreach ($titles as $title) {
            $title = trim((string) $title);
            $key = $this->normalizeText($title);
            if ($key === '' || isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $queries[] = [
                'title' => $title,
                'year' => max(0, (int) ($video['year'] ?? 0)),
                'media_type' => (string) ($video['media_type'] ?? ''),
                'people' => array_values(array_unique(array_merge($video['actors'] ?? [], $video['directors'] ?? []))),
            ];
        }
        return $queries;
    }

    public function match(array $video, callable $search): array
    {
        $found = [];
        foreach ($this->buildQueries($video) as $query) {
            foreach ((array) $search($query) as $candidate) {
                if (!is_array($candidate)) { continue; }
                $id = (int) ($candidate['id'] ?? 0);
                $type = strtolower(trim((string) ($candidate['media_type'] ?? '')));
                if ($id <= 0 || !in_array($type, ['movie', 'tv'], true)) { continue; }
                $key = $type . ':' . $id;
                if (!isset($found[$key])) { $found[$key] = $candidate; }
            }
        }
        $candidates = [];
        foreach ($found as $candidate) {
            $scored = $this->score($video, $candidate);
            $candidate['score'] = $scored['score'];
            $candidate['evidence'] = $scored['evidence'];
            $candidates[] = $candidate;
        }
        usort($candidates, static function (array $left, array $right): int {
            return ($right['score'] <=> $left['score']) ?: ((int) $left['id'] <=> (int) $right['id']);
        });
        if (!$candidates || $candidates[0]['score'] < 350) {
            return ['status' => 'no_match', 'candidates' => [], 'preselected_id' => 0, 'requires_review' => true];
        }
        $runnerUp = $candidates[1]['score'] ?? 0;
        $preselectedId = $candidates[0]['score'] >= 850 && $candidates[0]['score'] - $runnerUp >= 150
            ? (int) $candidates[0]['id'] : 0;
        return ['status' => 'candidate_review', 'candidates' => $candidates, 'preselected_id' => $preselectedId, 'requires_review' => true];
    }

    public function manual(string $type, int $id, callable $fetch): array
    {
        $type = strtolower(trim($type));
        if ($id <= 0 || !in_array($type, ['movie', 'tv'], true)) {
            throw new InvalidArgumentException('Manual TMDB type and ID are invalid.');
        }
        $candidate = $fetch($type, $id);
        if (!is_array($candidate) || (int) ($candidate['id'] ?? 0) !== $id
            || strtolower((string) ($candidate['media_type'] ?? '')) !== $type) {
            throw new RuntimeException('Manual TMDB candidate was not found.');
        }
        return [
            'status' => 'candidate_review', 'candidates' => [$candidate],
            'preselected_id' => $id, 'requires_review' => true, 'manual' => true,
        ];
    }

    public function score(array $video, array $candidate): array
    {
        $score = 0;
        $evidence = [];
        $videoTitles = array_filter(array_map([$this, 'normalizeText'], array_merge([
            $video['original_title'] ?? '', $video['title_en'] ?? '', $video['title_tw'] ?? '', $video['title_cn'] ?? '',
        ], $video['aliases'] ?? [])));
        $candidateTitles = array_filter(array_map([$this, 'normalizeText'], [
            $candidate['original_title'] ?? '', $candidate['title'] ?? '', $candidate['name'] ?? '',
        ]));
        if (array_intersect($videoTitles, $candidateTitles)) { $score += 500; $evidence[] = 'title'; }

        $videoYear = (int) ($video['year'] ?? 0);
        $candidateYear = (int) ($candidate['year'] ?? 0);
        if ($videoYear > 0 && $candidateYear > 0) {
            $difference = abs($videoYear - $candidateYear);
            if ($difference === 0) { $score += 200; $evidence[] = 'year'; }
            elseif ($difference === 1) { $score += 75; $evidence[] = 'year_near'; }
            else { $score -= 200; $evidence[] = 'year_conflict'; }
        }
        $videoType = strtolower((string) ($video['media_type'] ?? ''));
        $candidateType = strtolower((string) ($candidate['media_type'] ?? ''));
        if ($videoType !== '' && $candidateType !== '') {
            if ($videoType === $candidateType) { $score += 150; $evidence[] = 'media_type'; }
            else { $score -= 250; $evidence[] = 'media_type_conflict'; }
        }
        if ($this->overlaps($video['regions'] ?? [], $candidate['regions'] ?? [])) { $score += 75; $evidence[] = 'region'; }
        if ($this->overlaps($video['actors'] ?? [], $candidate['actors'] ?? [])) { $score += 60; $evidence[] = 'actor'; }
        if ($this->overlaps($video['directors'] ?? [], $candidate['directors'] ?? [])) { $score += 60; $evidence[] = 'director'; }
        return ['score' => $score, 'evidence' => $evidence];
    }

    private function overlaps(array $left, array $right): bool
    {
        $left = array_filter(array_map([$this, 'normalizeText'], $left));
        $right = array_filter(array_map([$this, 'normalizeText'], $right));
        return (bool) array_intersect($left, $right);
    }

    private function normalizeText($value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $value), 'UTF-8') : strtolower(trim((string) $value));
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?: '';
    }
}
