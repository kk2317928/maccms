<?php

namespace app\common\util;

use InvalidArgumentException;
use RuntimeException;
use think\Db;

class TmdbReviewJobHandler
{
    private $workspace;
    private $videoLoader;
    private $matcher;
    private $manualMatcher;
    private $transaction;

    public function __construct(TmdbReviewWorkspace $workspace = null, callable $videoLoader = null, callable $matcher = null, callable $manualMatcher = null, callable $transaction = null)
    {
        $this->workspace = $workspace ?: new TmdbReviewWorkspace();
        $this->transaction = $transaction ?: static fn (callable $callback) => Db::transaction($callback);
        $this->videoLoader = $videoLoader ?: function (int $vodId): array { return $this->loadVideo($vodId); };
        if ($matcher && $manualMatcher) { $this->matcher = $matcher; $this->manualMatcher = $manualMatcher; return; }
        $config = config('maccms'); $config = is_array($config) ? $config : [];
        $providerConfig = $config['ai_search']['external_sources']['sources']['tmdb'] ?? [];
        $provider = new TmdbExternalSourceProvider(is_array($providerConfig) ? $providerConfig : []);
        $service = new TmdbMatchService();
        $this->matcher = $matcher ?: static function (array $video) use ($service, $provider): array {
            return $service->match($video, static fn (array $query): array => $provider->searchMatch($query));
        };
        $this->manualMatcher = $manualMatcher ?: static function (string $type, int $id) use ($service, $provider): array {
            return $service->manual($type, $id, static fn (string $mediaType, int $tmdbId) => $provider->fetchMatch($mediaType, $tmdbId));
        };
    }

    public function match(array $payload): array
    {
        $vodId = $this->vodId($payload);
        $video = call_user_func($this->videoLoader, $vodId);
        $result = $this->reviewable((array) call_user_func($this->matcher, $video));
        return call_user_func($this->transaction, function () use ($vodId, $result): array { return $this->workspace->recordResult($vodId, $result); });
    }

    public function manual(array $payload): array
    {
        $vodId = $this->vodId($payload);
        $type = strtolower(trim((string) ($payload['media_type'] ?? '')));
        $tmdbId = (int) ($payload['tmdb_id'] ?? 0);
        if ($tmdbId <= 0 || !in_array($type, ['movie', 'tv'], true)) { throw new InvalidArgumentException('Manual TMDB job payload is invalid.'); }
        call_user_func($this->videoLoader, $vodId);
        $result = $this->reviewable((array) call_user_func($this->manualMatcher, $type, $tmdbId));
        return call_user_func($this->transaction, function () use ($vodId, $result): array { return $this->workspace->recordResult($vodId, $result); });
    }

    protected function loadVideo(int $vodId): array
    {
        $row = Db::name('vod')->alias('v')->join('__VOD_EXT__ e', 'e.vod_id=v.vod_id')->field('v.vod_name,v.vod_en,v.vod_year,v.vod_area,v.vod_actor,v.vod_director,e.original_title,e.title_tw,e.title_cn,e.title_en,e.old_titles_json,e.type2')->where('v.vod_id', $vodId)->find();
        if (!$row) { throw new RuntimeException('TMDB job video was not found.'); }
        $aliases = json_decode((string) ($row['old_titles_json'] ?? ''), true);
        return [
            'original_title' => (string) ($row['original_title'] ?: $row['vod_name']), 'title_en' => (string) ($row['title_en'] ?: $row['vod_en']),
            'title_tw' => (string) $row['title_tw'], 'title_cn' => (string) $row['title_cn'], 'aliases' => is_array($aliases) ? $aliases : [],
            'year' => (int) $row['vod_year'], 'media_type' => (string) $row['type2'] === 'movie' ? 'movie' : 'tv',
            'regions' => $this->split((string) $row['vod_area']), 'actors' => $this->split((string) $row['vod_actor']), 'directors' => $this->split((string) $row['vod_director']),
        ];
    }

    private function vodId(array $payload): int
    {
        $vodId = (int) ($payload['vod_id'] ?? 0);
        if ($vodId <= 0) { throw new InvalidArgumentException('TMDB job video ID is invalid.'); }
        return $vodId;
    }
    private function reviewable(array $result): array
    {
        if (($result['status'] ?? '') === 'no_match') { return ['status' => 'candidate_review', 'candidates' => [], 'preselected_id' => 0]; }
        return $result;
    }
    private function split(string $value): array { return array_values(array_filter(array_map('trim', preg_split('/[,，]/u', $value) ?: []), 'strlen')); }
}
