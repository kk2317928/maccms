<?php

namespace app\common\util;

use think\Db;

class DuplicateCandidateDetector
{
    private $scorer;
    private $repository;
    private $threshold;
    private $clock;

    public function __construct(
        DuplicateCandidateScorer $scorer = null,
        DuplicateCandidateRepository $repository = null,
        int $threshold = 650,
        callable $clock = null
    ) {
        $this->scorer = $scorer ?: new DuplicateCandidateScorer();
        $this->repository = $repository ?: new DuplicateCandidateRepository();
        $this->threshold = max(0, min(1000, $threshold));
        $this->clock = $clock ?: 'time';
    }

    public function detect(int $vodId, array $normalized): array
    {
        if ($vodId < 1) {
            throw new \InvalidArgumentException('Duplicate detection requires a positive video ID.');
        }
        $source = $this->fixtureFromNormalized($normalized);
        $sourceFingerprint = $this->fingerprint($source);
        $recorded = [];
        foreach ($this->loadCandidates($vodId) as $row) {
            $otherId = (int) ($row['vod_id'] ?? 0);
            if ($otherId < 1 || $otherId === $vodId) {
                continue;
            }
            $other = $this->fixtureFromRow($row);
            $result = $this->scorer->score($source, $other);
            if ((int) $result['score'] < $this->threshold) {
                continue;
            }
            $recorded[] = $this->repository->record(
                $vodId,
                $otherId,
                (int) $result['score'],
                (array) $result['evidence'],
                $sourceFingerprint,
                $this->fingerprint($other)
            );
        }
        $this->markChecked($vodId, (int) call_user_func($this->clock));
        return ['candidates_recorded' => count($recorded), 'candidate_ids' => $recorded];
    }

    protected function loadCandidates(int $vodId): array
    {
        $prefix = (string) config('database.prefix');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix)) {
            throw new \RuntimeException('Invalid database table prefix.');
        }
        $rows = Db::name('vod')->alias('v')
            ->join($prefix . 'vod_ext e', 'e.vod_id=v.vod_id', 'left')
            ->where('v.vod_id', '<>', $vodId)
            ->where('e.merged_into_vod_id', 0)
            ->field('v.vod_id,v.vod_name,v.vod_en,v.vod_year,v.vod_actor,v.vod_director,e.title_tw,e.title_cn,e.title_en,e.original_title,e.old_titles_json,e.type2,e.tmdb_id')
            ->order('v.vod_time desc,v.vod_id desc')
            ->limit(2000)
            ->select();
        return is_array($rows) ? $rows : [];
    }

    protected function markChecked(int $vodId, int $now): void
    {
        Db::name('vod_ext')->where('vod_id', $vodId)->update(['duplicate_checked_at' => $now, 'updated_at' => $now]);
    }

    private function fixtureFromNormalized(array $item): array
    {
        return [
            'tmdb_id' => (int) ($item['tmdb_id'] ?? 0),
            'original_title' => (string) ($item['original_title'] ?? ''),
            'title_tw' => (string) ($item['title_tw'] ?? ''),
            'title_cn' => (string) ($item['title_cn'] ?? ''),
            'title_en' => (string) ($item['title_en'] ?? ''),
            'aliases' => (array) ($item['aliases'] ?? []),
            'year' => (int) ($item['year'] ?? 0),
            'media_type' => (string) ($item['media_type'] ?? ''),
            'actors' => $this->split((string) ($item['actors'] ?? '')),
            'directors' => $this->split((string) ($item['directors'] ?? '')),
        ];
    }

    private function fixtureFromRow(array $row): array
    {
        $aliases = json_decode((string) ($row['old_titles_json'] ?? ''), true);
        if (!is_array($aliases)) {
            $aliases = [];
        }
        return [
            'tmdb_id' => (int) ($row['tmdb_id'] ?? 0),
            'original_title' => (string) ($row['original_title'] ?? ''),
            'title_tw' => (string) ($row['title_tw'] ?? $row['vod_name'] ?? ''),
            'title_cn' => (string) ($row['title_cn'] ?? ''),
            'title_en' => (string) ($row['title_en'] ?? $row['vod_en'] ?? ''),
            'aliases' => $aliases,
            'year' => (int) ($row['vod_year'] ?? 0),
            'media_type' => (string) ($row['type2'] ?? ''),
            'actors' => $this->split((string) ($row['vod_actor'] ?? '')),
            'directors' => $this->split((string) ($row['vod_director'] ?? '')),
        ];
    }

    private function split(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,，、\\/]+/u', $value) ?: []), static fn (string $item): bool => $item !== ''));
    }

    private function fingerprint(array $fixture): string
    {
        return hash('sha256', (string) json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
