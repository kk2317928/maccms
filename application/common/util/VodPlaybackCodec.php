<?php

namespace app\common\util;

use InvalidArgumentException;

final class VodPlaybackCodec
{
    private const SOURCE_SEPARATOR = '$$$';
    private const EPISODE_SEPARATOR = '#';
    private const NAME_SEPARATOR = '$';
    private const FORMATS = ['named', 'url_only', 'empty'];
    private const UNSAFE_SCHEMES = ['javascript', 'file', 'data', 'gopher', 'ftp'];

    public static function decode(string $from, string $url, string $server = '', string $note = ''): array
    {
        $columns = [
            'source' => explode(self::SOURCE_SEPARATOR, $from),
            'url' => explode(self::SOURCE_SEPARATOR, $url),
            'server' => explode(self::SOURCE_SEPARATOR, $server),
            'note' => explode(self::SOURCE_SEPARATOR, $note),
        ];
        $sourceCount = max(array_map('count', $columns));
        $sources = [];

        for ($index = 0; $index < $sourceCount; $index++) {
            $source = $columns['source'][$index] ?? '';
            $sourceUrl = $columns['url'][$index] ?? '';
            self::assertNoControlCharacters($source, 'source');

            $episodes = [];
            foreach (explode(self::EPISODE_SEPARATOR, $sourceUrl) as $record) {
                if ($record === '') {
                    $episode = ['name' => '', 'url' => '', 'format' => 'empty'];
                } elseif (strpos($record, self::NAME_SEPARATOR) === false) {
                    $episode = ['name' => '', 'url' => $record, 'format' => 'url_only'];
                } else {
                    [$name, $episodeUrl] = explode(self::NAME_SEPARATOR, $record, 2);
                    $episode = ['name' => $name, 'url' => $episodeUrl, 'format' => 'named'];
                }
                self::assertSafeUrl($episode['url']);
                $episodes[] = $episode;
            }

            $sources[] = [
                'source' => $source,
                'server' => $columns['server'][$index] ?? '',
                'note' => $columns['note'][$index] ?? '',
                'episodes' => $episodes,
            ];
        }

        return $sources;
    }

    public static function encode(array $sources): array
    {
        self::validateSources($sources);
        $from = [];
        $url = [];
        $server = [];
        $note = [];

        foreach ($sources as $source) {
            $from[] = $source['source'];
            $server[] = $source['server'];
            $note[] = $source['note'];
            $records = [];

            foreach ($source['episodes'] as $episode) {
                if ($episode['format'] === 'empty') {
                    $records[] = '';
                } elseif ($episode['format'] === 'url_only') {
                    $records[] = $episode['url'];
                } else {
                    $records[] = $episode['name'] . self::NAME_SEPARATOR . $episode['url'];
                }
            }
            $url[] = implode(self::EPISODE_SEPARATOR, $records);
        }

        return [
            'from' => implode(self::SOURCE_SEPARATOR, $from),
            'url' => implode(self::SOURCE_SEPARATOR, $url),
            'server' => self::parallelColumn($server),
            'note' => self::parallelColumn($note),
        ];
    }

    public static function merge(array $primary, array $secondary): array
    {
        self::validateSources($primary);
        self::validateSources($secondary);

        $merged = [];
        $sourceIndexes = [];
        foreach ($primary as $source) {
            $source['episodes'] = self::deduplicateEpisodes($source['episodes']);
            $merged[] = $source;
            if (!array_key_exists($source['source'], $sourceIndexes)) {
                $sourceIndexes[$source['source']] = count($merged) - 1;
            }
        }

        foreach ($secondary as $source) {
            if (!array_key_exists($source['source'], $sourceIndexes)) {
                $source['episodes'] = self::deduplicateEpisodes($source['episodes']);
                $sourceIndexes[$source['source']] = count($merged);
                $merged[] = $source;
                continue;
            }

            $index = $sourceIndexes[$source['source']];
            $merged[$index]['episodes'] = self::deduplicateEpisodes(array_merge(
                $merged[$index]['episodes'],
                $source['episodes']
            ));
        }

        self::validateSources($merged);
        return $merged;
    }

    private static function validateSources(array $sources): void
    {
        foreach ($sources as $source) {
            self::assertExactKeys($source, ['source', 'server', 'note', 'episodes'], 'source');
            if (!is_string($source['source']) || !is_string($source['server']) || !is_string($source['note'])) {
                throw new InvalidArgumentException('Playback source metadata must be strings.');
            }
            if (!is_array($source['episodes'])) {
                throw new InvalidArgumentException('Playback episodes must be an array.');
            }

            self::assertNoControlCharacters($source['source'], 'source');
            foreach (['source', 'server', 'note'] as $field) {
                if (strpos($source[$field], self::SOURCE_SEPARATOR) !== false) {
                    throw new InvalidArgumentException("Playback {$field} contains a reserved separator.");
                }
            }

            foreach ($source['episodes'] as $episode) {
                self::assertExactKeys($episode, ['name', 'url', 'format'], 'episode');
                if (!is_string($episode['name']) || !is_string($episode['url']) || !is_string($episode['format'])) {
                    throw new InvalidArgumentException('Playback episode values must be strings.');
                }
                if (!in_array($episode['format'], self::FORMATS, true)) {
                    throw new InvalidArgumentException('Unknown playback episode format.');
                }
                if (strpos($episode['name'], self::EPISODE_SEPARATOR) !== false
                    || strpos($episode['name'], self::NAME_SEPARATOR) !== false) {
                    throw new InvalidArgumentException('Playback episode name contains a reserved separator.');
                }
                if (strpos($episode['url'], self::EPISODE_SEPARATOR) !== false
                    || strpos($episode['url'], self::SOURCE_SEPARATOR) !== false) {
                    throw new InvalidArgumentException('Playback episode URL contains a reserved separator.');
                }
                if ($episode['format'] === 'named' && strncmp($episode['url'], '$$', 2) === 0) {
                    throw new InvalidArgumentException('Named playback URL would complete a source separator.');
                }
                if ($episode['format'] === 'url_only'
                    && ($episode['url'] === '' || strpos($episode['url'], self::NAME_SEPARATOR) !== false)) {
                    throw new InvalidArgumentException('URL-only playback record is not representable.');
                }
                if ($episode['format'] === 'empty' && ($episode['name'] !== '' || $episode['url'] !== '')) {
                    throw new InvalidArgumentException('Empty playback record must have an empty name and URL.');
                }
                if ($episode['format'] !== 'named' && $episode['name'] !== '') {
                    throw new InvalidArgumentException('Only named playback records may have a name.');
                }
                self::assertSafeUrl($episode['url']);
            }
        }
    }

    private static function assertExactKeys($value, array $expected, string $label): void
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException("Playback {$label} must be an array.");
        }
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new InvalidArgumentException("Playback {$label} has an invalid shape.");
        }
    }

    private static function assertSafeUrl(string $url): void
    {
        self::assertNoControlCharacters($url, 'URL');
        $candidate = ltrim($url, ' ');
        if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $candidate, $matches)
            && in_array(strtolower($matches[1]), self::UNSAFE_SCHEMES, true)) {
            throw new InvalidArgumentException('Playback URL uses an unsafe scheme.');
        }
    }

    private static function assertNoControlCharacters(string $value, string $label): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException("Playback {$label} contains control characters.");
        }
    }

    private static function parallelColumn(array $values): string
    {
        foreach ($values as $value) {
            if ($value !== '') {
                return implode(self::SOURCE_SEPARATOR, $values);
            }
        }
        return '';
    }

    private static function deduplicateEpisodes(array $episodes): array
    {
        $result = [];
        $seen = [];
        foreach ($episodes as $episode) {
            $normalizedUrl = trim($episode['url']);
            if ($normalizedUrl !== '') {
                if (isset($seen[$normalizedUrl])) {
                    continue;
                }
                $seen[$normalizedUrl] = true;
            }
            $result[] = $episode;
        }
        return $result;
    }
}
