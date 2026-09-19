<?php
namespace app\common\util;

class TmdbExternalSourceProvider implements ExternalSourceProviderInterface
{
    private $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function getCode()
    {
        return 'tmdb';
    }

    public function getLabel()
    {
        return 'TMDB';
    }

    public function search($keyword, array $options = [])
    {
        $keyword = trim((string)$keyword);
        if ($keyword === '' || !$this->isEnabled()) {
            return [];
        }
        $limit = max(1, min(20, intval(isset($options['limit']) ? $options['limit'] : 8)));
        $params = [
            'query' => $keyword,
            'page' => 1,
            'include_adult' => 'false',
            'language' => $this->getLanguage(),
            'region' => $this->getRegion(),
        ];
        $rows = $this->request('/search/multi', $params);
        if (!is_array($rows)) {
            return [];
        }
        return array_slice($this->normalizeList($rows), 0, $limit);
    }

    public function fetchRecent(array $options = [])
    {
        if (!$this->isEnabled()) {
            return [];
        }
        $limit = max(1, min(50, intval(isset($options['limit']) ? $options['limit'] : 20)));
        $params = [
            'language' => $this->getLanguage(),
        ];
        $rows = $this->request('/trending/all/day', $params);
        if (!is_array($rows)) {
            return [];
        }
        return array_slice($this->normalizeList($rows), 0, $limit);
    }

    public function searchMatch(array $query)
    {
        if (!$this->isEnabled()) { return []; }
        $type = strtolower(trim((string)(isset($query['media_type']) ? $query['media_type'] : '')));
        $path = in_array($type, ['movie', 'tv'], true) ? '/search/' . $type : '/search/multi';
        $params = ['query' => trim((string)(isset($query['title']) ? $query['title'] : '')), 'page' => 1, 'include_adult' => 'false', 'language' => $this->getLanguage(), 'region' => $this->getRegion()];
        if ($params['query'] === '') { return []; }
        if (!empty($query['year'])) { $params[$type === 'tv' ? 'first_air_date_year' : 'year'] = (int)$query['year']; }
        $rows = $this->request($path, $params);
        $out = [];
        foreach ((array)$rows as $row) { $candidate = $this->normalizeMatchCandidate($row, $type); if ($candidate) { $out[] = $candidate; } }
        return $out;
    }

    public function fetchMatch($type, $id)
    {
        $type = strtolower(trim((string)$type)); $id = intval($id);
        if (!$this->isEnabled() || $id <= 0 || !in_array($type, ['movie', 'tv'], true)) { return null; }
        $row = $this->requestObject('/' . $type . '/' . $id, ['language' => $this->getLanguage(), 'append_to_response' => 'credits,videos']);
        return is_array($row) ? $this->normalizeMatchCandidate($row, $type) : null;
    }

    private function isEnabled()
    {
        return (string)$this->get('enabled', '0') === '1' && $this->getApiKey() !== '';
    }

    private function get($key, $default = '')
    {
        return isset($this->config[$key]) ? $this->config[$key] : $default;
    }

    private function getApiKey()
    {
        return trim((string)$this->get('api_key', ''));
    }

    private function getBaseUrl()
    {
        $url = rtrim(trim((string)$this->get('base_url', 'https://api.themoviedb.org/3')), '/');
        return $url === '' ? 'https://api.themoviedb.org/3' : $url;
    }

    private function getImageBaseUrl()
    {
        $url = rtrim(trim((string)$this->get('image_base_url', 'https://image.tmdb.org/t/p/w500')), '/');
        return $url === '' ? 'https://image.tmdb.org/t/p/w500' : $url;
    }

    private function getLanguage()
    {
        $lang = trim((string)$this->get('language', 'zh-CN'));
        return $lang === '' ? 'zh-CN' : $lang;
    }

    private function getRegion()
    {
        return trim((string)$this->get('region', 'CN'));
    }

    private function request($path, array $params)
    {
        $json = $this->requestObject($path, $params);
        return is_array($json) && isset($json['results']) && is_array($json['results']) ? $json['results'] : [];
    }

    private function requestObject($path, array $params)
    {
        $params['api_key'] = $this->getApiKey();
        $url = $this->getBaseUrl() . $path . '?' . http_build_query($params);
        $headers = ['Accept: application/json'];
        $resp = HttpClient::curlPostWithTimeout($url, '', $headers, 10, false);
        if ($resp === false || $resp === '') {
            return [];
        }
        $json = json_decode((string)$resp, true);
        return is_array($json) ? $json : [];
    }

    private function normalizeMatchCandidate(array $row, $fallbackType)
    {
        $type = strtolower((string)(isset($row['media_type']) ? $row['media_type'] : $fallbackType));
        $id = intval(isset($row['id']) ? $row['id'] : 0);
        if ($id <= 0 || !in_array($type, ['movie', 'tv'], true)) { return null; }
        $date = (string)(isset($row['release_date']) ? $row['release_date'] : (isset($row['first_air_date']) ? $row['first_air_date'] : ''));
        $cast = isset($row['credits']['cast']) && is_array($row['credits']['cast']) ? array_slice(array_column($row['credits']['cast'], 'name'), 0, 10) : [];
        $crew = isset($row['credits']['crew']) && is_array($row['credits']['crew']) ? $row['credits']['crew'] : [];
        $directors = [];
        foreach ($crew as $person) { if (isset($person['job']) && $person['job'] === 'Director' && !empty($person['name'])) { $directors[] = $person['name']; } }
        $genres = isset($row['genres']) && is_array($row['genres']) ? array_column($row['genres'], 'name') : [];
        $regions = isset($row['origin_country']) && is_array($row['origin_country']) ? $row['origin_country'] : [];
        $poster = trim((string)(isset($row['poster_path']) ? $row['poster_path'] : ''));
        return [
            'id' => $id, 'media_type' => $type,
            'original_title' => trim((string)(isset($row['original_title']) ? $row['original_title'] : (isset($row['original_name']) ? $row['original_name'] : ''))),
            'title' => trim((string)(isset($row['title']) ? $row['title'] : (isset($row['name']) ? $row['name'] : ''))),
            'title_tw' => '', 'title_cn' => '', 'title_en' => '',
            'overview' => trim((string)(isset($row['overview']) ? $row['overview'] : '')),
            'year' => preg_match('/^[0-9]{4}/', $date, $match) ? intval($match[0]) : 0,
            'regions' => $regions, 'genres' => $genres, 'actors' => array_values(array_filter($cast)),
            'directors' => array_values(array_unique($directors)),
            'poster_url' => $poster === '' ? '' : $this->getImageBaseUrl() . $poster,
            'backdrop_url' => '', 'trailer_url' => '', 'score' => floatval(isset($row['vote_average']) ? $row['vote_average'] : 0),
        ];
    }

    private function normalizeList(array $rows)
    {
        $out = [];
        foreach ($rows as $row) {
            $mediaType = strtolower((string)(isset($row['media_type']) ? $row['media_type'] : ''));
            if ($mediaType === 'person') {
                continue;
            }
            $id = intval(isset($row['id']) ? $row['id'] : 0);
            if ($id <= 0) {
                continue;
            }
            $title = trim((string)(isset($row['title']) ? $row['title'] : (isset($row['name']) ? $row['name'] : '')));
            if ($title === '') {
                continue;
            }
            $releaseDate = trim((string)(isset($row['release_date']) ? $row['release_date'] : (isset($row['first_air_date']) ? $row['first_air_date'] : '')));
            $overview = trim((string)(isset($row['overview']) ? $row['overview'] : ''));
            $vote = floatval(isset($row['vote_average']) ? $row['vote_average'] : 0);
            if ($overview === '') {
                $bits = array_filter([
                    $mediaType !== '' ? strtoupper($mediaType) : '',
                    $releaseDate !== '' ? $releaseDate : '',
                    $vote > 0 ? ('TMDB ★ ' . round($vote, 1)) : '',
                ]);
                $overview = implode(' · ', $bits);
            }
            $coverPath = trim((string)(isset($row['poster_path']) ? $row['poster_path'] : ''));
            $cover = $coverPath === '' ? '' : $this->getImageBaseUrl() . $coverPath;
            $itemKey = $mediaType . '_' . $id;
            $out[] = [
                'provider_code' => 'tmdb',
                'item_key' => $itemKey,
                'item_mid' => $mediaType === 'tv' ? 1 : 1,
                'item_title' => $title,
                'item_subtitle' => strtoupper($mediaType === '' ? 'mixed' : $mediaType),
                'item_snippet' => $overview,
                'item_url' => 'https://www.themoviedb.org/' . ($mediaType === 'tv' ? 'tv' : 'movie') . '/' . $id,
                'item_cover' => $cover,
                'item_score' => $vote,
                'item_release_date' => $releaseDate,
                'item_payload' => json_encode($row, JSON_UNESCAPED_UNICODE),
            ];
        }
        return $out;
    }
}
