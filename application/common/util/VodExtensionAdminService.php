<?php

namespace app\common\util;

use app\common\model\VodExt;
use InvalidArgumentException;
use think\Db;

class VodExtensionAdminService
{
    private const TEXT_LIMITS = [
        'title_tw' => 255,
        'title_cn' => 255,
        'title_en' => 255,
        'original_title' => 255,
        'type2' => 32,
        'trailer_url' => 1024,
        'preview_url' => 1024,
        'old_poster' => 1024,
        'old_poster_s3' => 1024,
        'poster_s3' => 1024,
    ];

    public static function defaults(): array
    {
        return [
            'title_tw' => '',
            'title_cn' => '',
            'title_en' => '',
            'original_title' => '',
            'old_titles' => '',
            'old_titles_json' => null,
            'type2' => '',
            'tmdb_id' => 0,
            'tmdb_type' => '',
            'trailer_url' => '',
            'preview_url' => '',
            'old_poster' => '',
            'old_poster_s3' => '',
            'poster_s3' => '',
        ];
    }

    public static function load(int $vodId): array
    {
        $row = VodExt::ensureForVod($vodId);
        $result = array_merge(self::defaults(), array_intersect_key($row, self::defaults()));
        $oldTitles = json_decode((string) ($row['old_titles_json'] ?? ''), true);
        $result['old_titles'] = is_array($oldTitles) ? implode("\n", array_map('strval', $oldTitles)) : '';
        return $result;
    }

    public static function normalize(array $input): array
    {
        $normalized = [];
        foreach (self::TEXT_LIMITS as $field => $limit) {
            $value = self::scalar($input[$field] ?? '', $field);
            if (mb_strlen($value, 'UTF-8') > $limit) {
                throw new InvalidArgumentException($field . ' exceeds ' . $limit . ' characters.');
            }
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                throw new InvalidArgumentException($field . ' contains control characters.');
            }
            $normalized[$field] = $value;
        }

        if ($normalized['type2'] !== '' && !preg_match('/^[a-z0-9-]+$/', $normalized['type2'])) {
            throw new InvalidArgumentException('type2 must use lowercase letters, numbers and hyphens.');
        }

        $normalized['tmdb_type'] = self::scalar($input['tmdb_type'] ?? '', 'tmdb_type');
        if (!in_array($normalized['tmdb_type'], ['', 'movie', 'tv'], true)) {
            throw new InvalidArgumentException('tmdb_type must be movie, tv or empty.');
        }

        $tmdbId = $input['tmdb_id'] ?? 0;
        if (is_array($tmdbId) || !preg_match('/^\d+$/', (string) $tmdbId)) {
            throw new InvalidArgumentException('tmdb_id must be a non-negative integer.');
        }
        $normalized['tmdb_id'] = (int) $tmdbId;

        $oldTitlesRaw = self::scalar($input['old_titles'] ?? '', 'old_titles');
        $oldTitles = preg_split('/\r\n|\r|\n/', $oldTitlesRaw) ?: [];
        $oldTitles = array_values(array_unique(array_filter(array_map('trim', $oldTitles), static function ($value) {
            return $value !== '';
        })));
        foreach ($oldTitles as $title) {
            if (mb_strlen($title, 'UTF-8') > 255) {
                throw new InvalidArgumentException('Each old title must not exceed 255 characters.');
            }
        }
        $normalized['old_titles_json'] = $oldTitles === []
            ? null
            : json_encode($oldTitles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $normalized;
    }

    public static function save(int $vodId, array $input): void
    {
        $normalized = self::normalize($input);
        Db::transaction(function () use ($vodId, $normalized) {
            $current = VodExt::ensureForVod($vodId);
            $governance = new FieldGovernance();
            foreach ($normalized as $field => $value) {
                $stored = $current[$field] ?? null;
                if ((string) $stored === (string) $value) {
                    continue;
                }
                $governance->apply($vodId, $field, $value, 'manual', 'admin:vod/info');
            }
        });
    }

    private static function scalar($value, string $field): string
    {
        if (is_array($value) || is_object($value)) {
            throw new InvalidArgumentException($field . ' must be a scalar value.');
        }
        return trim((string) $value);
    }
}
