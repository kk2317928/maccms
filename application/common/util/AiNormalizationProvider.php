<?php

namespace app\common\util;

use RuntimeException;
use think\Db;

class AiNormalizationProvider
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function __invoke(array $request): array
    {
        $payload = (array) ($request['payload'] ?? []);
        $vodId = (int) ($payload['vod_id'] ?? 0);
        $vod = $vodId > 0 ? Db::name('vod')->where('vod_id', $vodId)->find() : null;
        $ext = $vodId > 0 ? Db::name('vod_ext')->where('vod_id', $vodId)->find() : null;
        if (!$vod || !$ext) {
            throw new RuntimeException('AI normalization video was not found.');
        }

        $input = [
            'vod_id' => $vodId,
            'title' => (string) ($vod['vod_name'] ?? ''),
            'subtitle' => (string) ($vod['vod_sub'] ?? ''),
            'english_title' => (string) ($vod['vod_en'] ?? ''),
            'year' => (string) ($vod['vod_year'] ?? ''),
            'area' => (string) ($vod['vod_area'] ?? ''),
            'class' => (string) ($vod['vod_class'] ?? ''),
            'tags' => (string) ($vod['vod_tag'] ?? ''),
            'actors' => (string) ($vod['vod_actor'] ?? ''),
            'directors' => (string) ($vod['vod_director'] ?? ''),
            'title_tw' => (string) ($ext['title_tw'] ?? ''),
            'title_cn' => (string) ($ext['title_cn'] ?? ''),
            'title_en' => (string) ($ext['title_en'] ?? ''),
            'original_title' => (string) ($ext['original_title'] ?? ''),
            'media_type' => (string) ($ext['type2'] ?? ''),
        ];
        $system = 'Prompt version: ' . (string) ($this->config['prompt_version'] ?? 'normalize-v1') . '. You normalize film and television metadata. Return only one JSON object with exactly these keys: normalized_title, original_title, title_tw, title_cn, title_en, aliases, year, media_type, tmdb_clues, taxonomy, confidence, reason. media_type is movie, tv, anime, or short. taxonomy has regions, genres, tags string arrays. tmdb_clues has title, year, type where type is movie or tv. Do not use markdown.';
        $user = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $result = AiProvider::chat($this->config, $system, (string) $user);
        if ((int) ($result['code'] ?? 0) !== 1 || trim((string) ($result['text'] ?? '')) === '') {
            throw new RuntimeException('AI normalization provider failed.');
        }
        $raw = trim((string) $result['text']);
        if (preg_match('/^\x60\x60\x60(?:json)?\s*(.*?)\s*\x60\x60\x60$/is', $raw, $match)) {
            $raw = trim($match[1]);
        }
        $usage = isset($result['usage']) && is_array($result['usage']) ? $result['usage'] : null;
        $inputTokens = $usage === null ? max(0, (int) ceil(strlen($system . (string) $user) / 4)) : (int) $usage['input_tokens'];
        $outputTokens = $usage === null ? max(0, (int) ceil(strlen($raw) / 4)) : (int) $usage['output_tokens'];
        return [
            'raw_response' => $raw,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'estimated_cost_micros' => AiProvider::estimateCostMicros($inputTokens, $outputTokens, $this->config),
        ];
    }
}
