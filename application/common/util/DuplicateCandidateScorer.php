<?php

namespace app\common\util;

class DuplicateCandidateScorer
{
    public function score(array $left, array $right): array
    {
        $leftTmdb = (int) ($left['tmdb_id'] ?? 0);
        $rightTmdb = (int) ($right['tmdb_id'] ?? 0);
        if ($leftTmdb > 0 && $leftTmdb === $rightTmdb) {
            return $this->result(1000, 'tmdb_id');
        }

        $leftYear = (int) ($left['year'] ?? 0);
        $rightYear = (int) ($right['year'] ?? 0);
        $sameKnownYear = $leftYear > 0 && $leftYear === $rightYear;
        $leftOriginal = $this->normalize((string) ($left['original_title'] ?? ''));
        $rightOriginal = $this->normalize((string) ($right['original_title'] ?? ''));
        $leftType = $this->normalize((string) ($left['media_type'] ?? ''));
        $rightType = $this->normalize((string) ($right['media_type'] ?? ''));
        if ($sameKnownYear && $leftOriginal !== '' && $leftOriginal === $rightOriginal
            && $leftType !== '' && $leftType === $rightType) {
            return $this->result(900, 'original_title_year_type');
        }

        $titleOverlap = array_values(array_intersect($this->titles($left), $this->titles($right)));
        if ($sameKnownYear && $titleOverlap) {
            return $this->result(750, 'multilingual_title_year');
        }

        $peopleOverlap = array_values(array_intersect($this->people($left), $this->people($right)));
        if ($titleOverlap && $peopleOverlap) {
            return $this->result(650, 'title_people');
        }

        if ($titleOverlap && $leftYear === 0 && $rightYear === 0) {
            return $this->result(350, 'title_only_unknown_year');
        }
        if ($titleOverlap && $leftYear > 0 && $rightYear > 0 && $leftYear !== $rightYear) {
            return ['score' => 100, 'evidence' => ['year_conflict', 'title_match']];
        }
        return ['score' => 0, 'evidence' => []];
    }

    private function result(int $score, string $evidence): array
    {
        return ['score' => $score, 'evidence' => [$evidence]];
    }

    private function titles(array $item): array
    {
        $values = [];
        foreach (['title_tw', 'title_cn', 'title_en'] as $field) {
            $values[] = $item[$field] ?? '';
        }
        foreach (($item['aliases'] ?? []) as $alias) {
            $values[] = $alias;
        }
        return $this->normalizedSet($values);
    }

    private function people(array $item): array
    {
        return $this->normalizedSet(array_merge($item['actors'] ?? [], $item['directors'] ?? []));
    }

    private function normalizedSet(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }
            $normalized = $this->normalize($value);
            if ($normalized !== '') {
                $result[$normalized] = true;
            }
        }
        $result = array_keys($result);
        sort($result, SORT_STRING);
        return $result;
    }

    private function normalize(string $value): string
    {
        $value = trim($value);
        if (function_exists('mb_strtolower')) {
            $value = mb_strtolower($value, 'UTF-8');
        } else {
            $value = strtolower($value);
        }
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
        return is_string($value) ? $value : '';
    }
}
