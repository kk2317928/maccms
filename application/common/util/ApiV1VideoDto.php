<?php
namespace app\common\util;

final class ApiV1VideoDto implements ApiV1Dto
{
    private $data;
    private function __construct(array $data) { $this->data = $data; }
    public function toArray() { return $this->data; }

    public static function summary(array $row)
    {
        $titles = self::titles($row);
        return new self(array(
            'public_id' => (string) self::value($row, 'public_id', ''),
            'title' => $titles['tw'] !== '' ? $titles['tw'] : (string) self::value($row, 'vod_name', ''),
            'titles' => $titles,
            'poster' => (string) (self::value($row, 'poster_s3', '') !== '' ? self::value($row, 'poster_s3', '') : self::value($row, 'vod_pic', '')),
            'year' => (string) self::value($row, 'vod_year', ''),
            'remarks' => (string) self::value($row, 'vod_remarks', ''),
            'score' => (float) self::value($row, 'vod_score', 0),
            'published_at' => (int) self::value($row, 'published_at', 0),
        ));
    }

    public static function detail(array $row, array $taxonomies)
    {
        $data = self::summary($row)->toArray();
        $data += array(
            'synopsis' => (string) self::value($row, 'vod_content', ''),
            'actors' => self::csv(self::value($row, 'vod_actor', '')),
            'directors' => self::csv(self::value($row, 'vod_director', '')),
            'area' => (string) self::value($row, 'vod_area', ''),
            'language' => (string) self::value($row, 'vod_lang', ''),
            'episode' => array(
                'current' => (int) self::value($row, 'vod_serial', 0),
                'total' => (int) self::value($row, 'vod_total', 0),
                'complete' => (bool) self::value($row, 'vod_isend', false),
            ),
            'trailer_url' => (string) self::value($row, 'trailer_url', ''),
            'preview_url' => (string) self::value($row, 'preview_url', ''),
            'taxonomies' => array_values($taxonomies),
        );
        return new self($data);
    }

    public static function taxonomy(array $row)
    {
        return array(
            'kind' => (string) self::value($row, 'kind', ''),
            'slug' => (string) self::value($row, 'slug', ''),
            'names' => array(
                'tw' => (string) self::value($row, 'name_tw', ''),
                'cn' => (string) self::value($row, 'name_cn', ''),
                'en' => (string) self::value($row, 'name_en', ''),
            ),
            'sort' => (int) self::value($row, 'sort', 0),
        );
    }

    private static function titles(array $row)
    {
        return array(
            'tw'=>(string) self::value($row,'title_tw',''),
            'cn'=>(string) self::value($row,'title_cn',''),
            'en'=>(string) self::value($row,'title_en',''),
            'original'=>(string) self::value($row,'original_title',''),
        );
    }
    private static function value(array $row, $key, $default) { return array_key_exists($key, $row) && $row[$key] !== null ? $row[$key] : $default; }
    private static function csv($value) { $parts = array_filter(array_map('trim', explode(',', (string) $value)), 'strlen'); return array_values($parts); }
}
