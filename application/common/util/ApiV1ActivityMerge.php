<?php
namespace app\common\util;

use InvalidArgumentException;

final class ApiV1ActivityMerge
{
    const MAX_ITEMS = 50;

    public static function normalize(array $payload)
    {
        $favorites = isset($payload['favorites']) ? $payload['favorites'] : array();
        $progress = isset($payload['progress']) ? $payload['progress'] : array();
        if (!is_array($favorites) || !is_array($progress) || count($favorites) + count($progress) > self::MAX_ITEMS) {
            throw new InvalidArgumentException('activity');
        }

        $favoriteMap = array();
        foreach ($favorites as $item) {
            if (!is_array($item)) { throw new InvalidArgumentException('favorites'); }
            $publicId = self::publicId(isset($item['public_id']) ? $item['public_id'] : '');
            $updatedAt = self::positiveInt(isset($item['updated_at']) ? $item['updated_at'] : null, 'updated_at');
            if (!isset($favoriteMap[$publicId]) || $favoriteMap[$publicId]['updated_at'] < $updatedAt) {
                $favoriteMap[$publicId] = array('public_id'=>$publicId, 'updated_at'=>$updatedAt);
            }
        }

        $progressMap = array();
        foreach ($progress as $item) {
            if (!is_array($item)) { throw new InvalidArgumentException('progress'); }
            $publicId = self::publicId(isset($item['public_id']) ? $item['public_id'] : '');
            $sourceId = self::identifier(isset($item['source_id']) ? $item['source_id'] : '', 'source_id');
            $episodeId = self::identifier(isset($item['episode_id']) ? $item['episode_id'] : '', 'episode_id');
            $position = self::nonNegativeInt(isset($item['position_seconds']) ? $item['position_seconds'] : null, 'position_seconds', 4294967295);
            $duration = self::nonNegativeInt(isset($item['duration_seconds']) ? $item['duration_seconds'] : null, 'duration_seconds', 4294967295);
            if ($duration > 0 && $position > $duration) { throw new InvalidArgumentException('position_seconds'); }
            $updatedAt = self::positiveInt(isset($item['updated_at']) ? $item['updated_at'] : null, 'updated_at');
            $key = $publicId."\0".$sourceId."\0".$episodeId;
            if (!isset($progressMap[$key]) || $progressMap[$key]['updated_at'] < $updatedAt) {
                $progressMap[$key] = array(
                    'public_id'=>$publicId, 'source_id'=>$sourceId, 'episode_id'=>$episodeId,
                    'position_seconds'=>$position, 'duration_seconds'=>$duration, 'updated_at'=>$updatedAt,
                );
            }
        }
        ksort($favoriteMap);
        ksort($progressMap);
        return array('favorites'=>array_values($favoriteMap), 'progress'=>array_values($progressMap));
    }

    private static function publicId($value)
    {
        $value = strtoupper(trim((string)$value));
        if (preg_match('/\A[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('public_id');
        }
        return $value;
    }

    private static function identifier($value, $field)
    {
        $value = trim((string)$value);
        if (preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/D', $value) !== 1) { throw new InvalidArgumentException($field); }
        return $value;
    }

    private static function positiveInt($value, $field)
    {
        $value = self::nonNegativeInt($value, $field);
        if ($value < 1) { throw new InvalidArgumentException($field); }
        return $value;
    }

    private static function nonNegativeInt($value, $field, $maximum = PHP_INT_MAX)
    {
        if (is_int($value)) { $integer = $value; }
        elseif (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1) { $integer = (int)$value; }
        else { throw new InvalidArgumentException($field); }
        if ($integer < 0 || $integer > $maximum) { throw new InvalidArgumentException($field); }
        return $integer;
    }
}
