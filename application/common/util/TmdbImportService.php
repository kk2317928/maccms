<?php

namespace app\common\util;

use InvalidArgumentException;

class TmdbImportService
{
    private $taxonomySuggestions;
    private $enqueue;

    public function __construct($taxonomySuggestions = false, callable $enqueue = null)
    {
        $this->taxonomySuggestions = $taxonomySuggestions === false ? new TaxonomySuggestionService() : $taxonomySuggestions;
        $this->enqueue = $enqueue ?: static function (string $type, array $payload, string $key): array {
            return (new ContentJobRepository())->enqueue($type, $payload, $key);
        };
    }

    public function preview(int $vodId, array $candidate, FieldGovernance $fields): array
    {
        $mapped = $this->mapCandidate($candidate);
        $preview = [];
        foreach ($mapped as $field => $value) {
            if ($this->isMissing($value)) { continue; }
            $current = $fields->inspect($vodId, $field);
            $preview[$field] = [
                'current' => $current['value'],
                'candidate' => $value,
                'changed' => $current['value'] !== $value,
                'source' => (string) ($current['state']['source'] ?? ''),
                'locked' => !empty($current['state']['is_locked']),
            ];
        }
        return $preview;
    }

    public function apply(
        int $vodId,
        array $candidate,
        array $approvedFields,
        FieldGovernance $fields,
        int $reviewerId,
        bool $overrideLocks = false
    ): array {
        if ($vodId <= 0 || $reviewerId <= 0) {
            throw new InvalidArgumentException('TMDB import video and reviewer IDs must be positive.');
        }
        $mapped = $this->mapCandidate($candidate);
        $type = strtolower(trim((string) ($candidate['media_type'] ?? '')));
        $id = (int) ($candidate['id'] ?? 0);
        if ($id <= 0 || !in_array($type, ['movie', 'tv'], true)) {
            throw new InvalidArgumentException('TMDB import candidate identity is invalid.');
        }
        $sourceRef = 'tmdb:' . $type . ':' . $id . ':admin:' . $reviewerId;
        if ($this->taxonomySuggestions !== null) {
            $this->taxonomySuggestions->stage($vodId, 'tmdb', $sourceRef, [
                'regions' => (array) ($candidate['regions'] ?? []),
                'genres' => (array) ($candidate['genres'] ?? []),
                'tags' => (array) ($candidate['tags'] ?? []),
            ]);
        }
        $applied = [];
        $blocked = [];
        foreach (array_values(array_unique($approvedFields)) as $field) {
            if ($this->taxonomySuggestions !== null && in_array($field, ['vod_area', 'vod_class', 'vod_tag'], true)) {
                throw new InvalidArgumentException('TMDB taxonomy must be adopted through reviewed term suggestions.');
            }
            if (!array_key_exists($field, $mapped) || $this->isMissing($mapped[$field])) {
                throw new InvalidArgumentException('Approved TMDB field is unavailable.');
            }
            if ($fields->apply($vodId, $field, $mapped[$field], 'tmdb', $sourceRef, true, $overrideLocks)) {
                $applied[] = $field;
            } else {
                $blocked[] = $field;
            }
        }
        $posterJob = null;
        if (in_array('vod_pic', $approvedFields, true) && !empty($mapped['vod_pic']) && !in_array('vod_pic', $blocked, true)) {
            // Poster bytes are never downloaded inside the review transaction. The
            // reviewed candidate only schedules the hardened ingestion worker.
            $posterJob = call_user_func($this->enqueue, 'tmdb_poster_ingest', [
                'vod_id' => $vodId, 'poster_url' => (string) $mapped['vod_pic'], 'source_ref' => $sourceRef,
            ], 'video:' . $vodId . ':tmdb_poster:' . hash('sha256', (string) $mapped['vod_pic']));
            $applied = array_values(array_diff($applied, ['vod_pic']));
        }
        return ['applied' => $applied, 'blocked' => $blocked, 'poster_job' => $posterJob];
    }

    private function mapCandidate(array $candidate): array
    {
        return [
            'tmdb_id' => (int) ($candidate['id'] ?? 0),
            'tmdb_type' => strtolower(trim((string) ($candidate['media_type'] ?? ''))),
            'original_title' => trim((string) ($candidate['original_title'] ?? '')),
            'title_tw' => trim((string) ($candidate['title_tw'] ?? '')),
            'title_cn' => trim((string) ($candidate['title_cn'] ?? '')),
            'title_en' => trim((string) ($candidate['title_en'] ?? '')),
            'vod_content' => trim((string) ($candidate['overview'] ?? '')),
            'vod_year' => (int) ($candidate['year'] ?? 0),
            'vod_area' => $this->join($candidate['regions'] ?? []),
            'vod_class' => $this->join($candidate['genres'] ?? []),
            'vod_actor' => $this->join($candidate['actors'] ?? []),
            'vod_director' => $this->join($candidate['directors'] ?? []),
            'vod_pic' => trim((string) ($candidate['poster_url'] ?? '')),
            'vod_pic_slide' => trim((string) ($candidate['backdrop_url'] ?? '')),
            'trailer_url' => trim((string) ($candidate['trailer_url'] ?? '')),
            'vod_score' => (float) ($candidate['score'] ?? 0),
        ];
    }

    private function join(array $values): string
    {
        return implode(',', array_values(array_unique(array_filter(array_map('trim', $values), 'strlen'))));
    }

    private function isMissing($value): bool
    {
        return $value === '' || $value === null || $value === 0 || $value === 0.0 || $value === [];
    }
}
