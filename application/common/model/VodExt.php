<?php

namespace app\common\model;

use app\common\util\PublicIdGenerator;
use InvalidArgumentException;
use RuntimeException;
use think\Db;
use Throwable;

class VodExt extends Base
{
    protected $name = 'vod_ext';
    protected $primaryId = 'vod_id';
    protected $pk = 'vod_id';
    protected $autoWriteTimestamp = false;

    public static function ensureForVod(int $vodId): array
    {
        self::assertVodId($vodId);

        $existing = self::findByVodId($vodId);
        if ($existing !== null) {
            return $existing;
        }

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $publicId = PublicIdGenerator::generate(function ($candidate) {
                return Db::name('vod_ext')->where('public_id', $candidate)->count() > 0;
            });
            $now = time();

            try {
                Db::name('vod_ext')->insert([
                    'vod_id' => $vodId,
                    'public_id' => $publicId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (Throwable $exception) {
                $existing = self::findByVodId($vodId);
                if ($existing !== null) {
                    return $existing;
                }
                if (self::isDuplicateKey($exception)) {
                    continue;
                }
                throw $exception;
            }

            $created = self::findByVodId($vodId);
            if ($created !== null) {
                return $created;
            }
        }

        throw new RuntimeException('Unable to persist a unique public ID for video.');
    }

    public static function findByPublicId(string $publicId): ?array
    {
        $publicId = strtoupper(trim($publicId));
        if (!preg_match('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}$/', $publicId)) {
            return null;
        }

        $row = Db::name('vod_ext')->where('public_id', $publicId)->find();
        return $row ?: null;
    }

    public static function canonicalVodId(int $vodId): int
    {
        return self::resolveCanonical($vodId, function ($currentVodId) {
            $row = Db::name('vod_ext')
                ->where('vod_id', $currentVodId)
                ->field('merged_into_vod_id')
                ->find();
            return $row ? (int) $row['merged_into_vod_id'] : null;
        });
    }

    public static function resolveCanonical(int $vodId, callable $lookup): int
    {
        self::assertVodId($vodId);
        $currentVodId = $vodId;
        $seen = [];

        for ($hops = 0; ; $hops++) {
            if (isset($seen[$currentVodId])) {
                throw new RuntimeException('Canonical video cycle detected.');
            }
            $seen[$currentVodId] = true;

            $nextVodId = $lookup($currentVodId);
            if ($nextVodId === null || (int) $nextVodId <= 0) {
                return $currentVodId;
            }
            if ($hops >= 10) {
                throw new RuntimeException('Canonical video chain exceeded maximum depth.');
            }

            $currentVodId = (int) $nextVodId;
        }
    }

    private static function findByVodId(int $vodId): ?array
    {
        $row = Db::name('vod_ext')->where('vod_id', $vodId)->find();
        return $row ?: null;
    }

    private static function assertVodId(int $vodId): void
    {
        if ($vodId <= 0) {
            throw new InvalidArgumentException('Video ID must be a positive integer.');
        }
    }

    private static function isDuplicateKey(Throwable $exception): bool
    {
        return (int) $exception->getCode() === 1062
            || stripos($exception->getMessage(), 'duplicate') !== false;
    }
}
