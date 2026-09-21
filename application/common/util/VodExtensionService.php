<?php
namespace app\common\util;

use app\common\model\MetaTerm;
use app\common\model\VodExt;
use app\common\model\VodFieldState;
use InvalidArgumentException;
use RuntimeException;
use think\Db;
use Throwable;

final class VodExtensionService
{
    public const AI_INPUT_FIELDS = [
        'type_id', 'vod_name', 'vod_sub', 'vod_en', 'vod_year', 'vod_area',
        'vod_lang', 'vod_actor', 'vod_director', 'vod_class', 'vod_tag',
        'vod_blurb', 'vod_content',
    ];

    public const NATIVE_FIELDS = [
        'region' => 'vod_area',
        'genre' => 'vod_class',
        'tag' => 'vod_tag',
    ];

    public static function ensure(int $vodId): array
    {
        self::assertVodId($vodId);
        if (Db::name('vod')->where('vod_id', $vodId)->count() < 1) {
            throw new InvalidArgumentException('Video does not exist.');
        }
        return VodExt::ensureForVod($vodId);
    }

    public static function contentFingerprint(array $data): string
    {
        $identity = [];
        foreach (self::AI_INPUT_FIELDS as $field) {
            $value = $data[$field] ?? '';
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (is_int($value) || is_float($value)) {
                $value = (string) $value;
            } elseif (!is_string($value)) {
                $value = '';
            }
            $identity[$field] = preg_replace('/\s+/u', ' ', trim($value));
        }
        return hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function enqueueAiAfterWrite(
        int $vodId,
        array $before,
        array $written,
        callable $coordinatorFactory = null
    ): ?array {
        self::assertVodId($vodId);
        $touched = array_intersect(self::AI_INPUT_FIELDS, array_keys($written));
        if (!$touched) {
            return null;
        }
        $after = array_merge($before, $written);
        // Production fingerprints must use the canonical row after database
        // defaults and validators have run. Injected coordinators keep this
        // method deterministic for the focused contract test.
        if ($coordinatorFactory === null) {
            $persisted = Db::name('vod')->where('vod_id', $vodId)
                ->field(implode(',', self::AI_INPUT_FIELDS))->find();
            if (!$persisted) {
                throw new RuntimeException('Video was not found after write.');
            }
            $after = (array) $persisted;
        }
        if ($before && self::contentFingerprint($before) === self::contentFingerprint($after)) {
            return null;
        }
        $factory = $coordinatorFactory ?: static function () {
            return new ContentWorkflowCoordinator();
        };
        $coordinator = $factory();
        if (!is_object($coordinator) || !method_exists($coordinator, 'beginAi')) {
            throw new RuntimeException('Invalid content workflow coordinator.');
        }
        return $coordinator->beginAi($vodId, self::contentFingerprint($after));
    }

    public static function replaceTerms(int $vodId, string $kind, array $termIds): void
    {
        self::assertVodId($vodId);
        MetaTerm::assertKind($kind);
        $termIds = array_values(array_unique(array_map('intval', $termIds)));
        foreach ($termIds as $termId) {
            if ($termId <= 0) {
                throw new InvalidArgumentException('Term IDs must be positive integers.');
            }
        }
        if ($termIds) {
            $validIds = Db::name('meta_term')->where('kind', $kind)->where('status', 1)
                ->where('term_id', 'in', $termIds)->column('term_id');
            sort($validIds);
            $expected = $termIds;
            sort($expected);
            if (array_map('intval', $validIds) !== $expected) {
                throw new InvalidArgumentException('Every term must exist, be active, and match the requested kind.');
            }
        }

        Db::startTrans();
        try {
            $currentIds = Db::name('vod_meta_term')->alias('vmt')
                ->join('__META_TERM__ mt', 'mt.term_id=vmt.term_id')
                ->where('vmt.vod_id', $vodId)->where('mt.kind', $kind)->column('vmt.term_id');
            if ($currentIds) {
                Db::name('vod_meta_term')->where('vod_id', $vodId)->where('term_id', 'in', $currentIds)->delete();
            }
            $now = time();
            foreach ($termIds as $termId) {
                Db::name('vod_meta_term')->insert(['vod_id' => $vodId, 'term_id' => $termId, 'created_at' => $now]);
            }
            Db::commit();
        } catch (Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    public static function lockField(int $vodId, string $field, string $source, string $sourceRef = ''): void
    {
        self::assertVodId($vodId);
        VodFieldState::assertSource($source);
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field)) {
            throw new InvalidArgumentException('Invalid field name.');
        }
        VodFieldState::upsertLock([
            'vod_id' => $vodId, 'field_name' => $field, 'source' => $source,
            'source_ref' => $sourceRef, 'is_locked' => 1, 'updated_at' => time(),
        ]);
    }

    public static function syncNativeTaxonomy(int $vodId): void
    {
        self::assertVodId($vodId);
        $rows = Db::name('vod_meta_term')->alias('vmt')
            ->join('__META_TERM__ mt', 'mt.term_id=vmt.term_id')
            ->field('mt.term_id,mt.kind,mt.name_tw,mt.name_cn,mt.name_en,mt.sort AS term_sort')
            ->where('vmt.vod_id', $vodId)->where('mt.status', 1)
            ->order('term_sort asc,term_id asc')->select();
        $names = ['region' => [], 'genre' => [], 'tag' => []];
        foreach ($rows as $row) {
            if (!isset($names[$row['kind']])) {
                continue;
            }
            $name = $row['name_tw'] ?: ($row['name_cn'] ?: $row['name_en']);
            if ($name !== '') {
                $names[$row['kind']][] = $name;
            }
        }
        $updates = [];
        foreach (self::NATIVE_FIELDS as $kind => $field) {
            $updates[$field] = implode(',', $names[$kind]);
        }
        Db::name('vod')->where('vod_id', $vodId)->update($updates);
    }

    private static function assertVodId(int $vodId): void
    {
        if ($vodId <= 0) {
            throw new InvalidArgumentException('Video ID must be a positive integer.');
        }
    }
}
