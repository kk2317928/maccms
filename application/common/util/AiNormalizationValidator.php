<?php

namespace app\common\util;

use InvalidArgumentException;
use JsonException;

class AiNormalizationValidator
{
    private const KEYS = [
        'normalized_title', 'original_title', 'title_tw', 'title_cn', 'title_en',
        'aliases', 'year', 'media_type', 'tmdb_clues', 'taxonomy', 'confidence', 'reason',
    ];

    public function validate(string $json): array
    {
        if ($json === '' || strlen($json) > 65536) {
            throw new InvalidArgumentException('AI response size is invalid.');
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('AI response is not valid JSON.', 0, $exception);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('AI response must be a JSON object.');
        }
        $keys = array_keys($data); sort($keys); $expected = self::KEYS; sort($expected);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('AI response fields do not match the schema.');
        }
        foreach (['normalized_title', 'original_title', 'title_tw', 'title_cn', 'title_en'] as $field) {
            $data[$field] = $this->text($data[$field], 255, true);
        }
        $data['aliases'] = $this->stringList($data['aliases'], 50, 255);
        if (!is_int($data['year']) || $data['year'] < 1870 || $data['year'] > (int) date('Y') + 2) {
            throw new InvalidArgumentException('AI year is invalid.');
        }
        if (!in_array($data['media_type'], ['movie', 'tv', 'anime', 'short'], true)) {
            throw new InvalidArgumentException('AI media type is invalid.');
        }
        $data['tmdb_clues'] = $this->tmdbClues($data['tmdb_clues']);
        $data['taxonomy'] = $this->taxonomy($data['taxonomy']);
        if (!is_int($data['confidence']) && !is_float($data['confidence'])) {
            throw new InvalidArgumentException('AI confidence must be numeric.');
        }
        $data['confidence'] = (float) $data['confidence'];
        if ($data['confidence'] < 0 || $data['confidence'] > 1) {
            throw new InvalidArgumentException('AI confidence is outside zero and one.');
        }
        $data['reason'] = $this->text($data['reason'], 1000, false);
        return $data;
    }

    private function tmdbClues($value): array
    {
        if (!is_array($value) || array_keys($value) !== ['title', 'year', 'type']) {
            throw new InvalidArgumentException('TMDB clues are invalid.');
        }
        $title = $this->text($value['title'], 255, true);
        if (!is_int($value['year']) || $value['year'] < 1870 || $value['year'] > (int) date('Y') + 2) {
            throw new InvalidArgumentException('TMDB clue year is invalid.');
        }
        if (!in_array($value['type'], ['movie', 'tv'], true)) {
            throw new InvalidArgumentException('TMDB clue type is invalid.');
        }
        return ['title' => $title, 'year' => $value['year'], 'type' => $value['type']];
    }

    private function taxonomy($value): array
    {
        if (!is_array($value) || array_keys($value) !== ['regions', 'genres', 'tags']) {
            throw new InvalidArgumentException('AI taxonomy is invalid.');
        }
        return [
            'regions' => $this->stringList($value['regions'], 20, 128),
            'genres' => $this->stringList($value['genres'], 30, 128),
            'tags' => $this->stringList($value['tags'], 50, 128),
        ];
    }

    private function stringList($value, int $maxItems, int $maxLength): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maxItems) {
            throw new InvalidArgumentException('AI string list is invalid.');
        }
        $result = [];
        foreach ($value as $item) {
            $item = $this->text($item, $maxLength, false);
            if (!in_array($item, $result, true)) { $result[] = $item; }
        }
        return $result;
    }

    private function text($value, int $maxLength, bool $allowEmpty): string
    {
        if (!is_string($value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('AI text value is invalid.');
        }
        $value = trim($value);
        if ((!$allowEmpty && $value === '') || strlen($value) > $maxLength) {
            throw new InvalidArgumentException('AI text length is invalid.');
        }
        return $value;
    }
}
