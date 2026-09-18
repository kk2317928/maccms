<?php
namespace app\common\model;

use InvalidArgumentException;

class VodFieldState extends Base
{
    public const SOURCES = ['import', 'ai', 'tmdb', 'manual'];
    protected $name = 'vod_field_state';
    protected $primaryId = 'vod_id';
    protected $pk = 'vod_id';
    protected $autoWriteTimestamp = false;

    public static function assertSource(string $source): void
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException("Unsupported field source: {$source}.");
        }
    }

    public static function upsertLock(array $row): int
    {
        return (new static())->upsertByUnique($row);
    }
}
