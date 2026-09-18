<?php

namespace app\common\util;

use InvalidArgumentException;
use think\Db;

class FieldGovernance
{
    public const EXT_FIELDS = [
        'title_tw', 'title_cn', 'title_en', 'original_title', 'old_titles_json',
        'type2', 'tmdb_id', 'tmdb_type', 'trailer_url', 'preview_url',
        'old_poster', 'old_poster_s3', 'poster_s3',
    ];
    public const NATIVE_FIELDS = [
        'vod_name', 'vod_sub', 'vod_en', 'vod_year', 'vod_area', 'vod_class',
        'vod_tag', 'vod_actor', 'vod_director', 'vod_content', 'vod_pic',
        'vod_pic_thumb', 'vod_pic_slide', 'vod_score',
    ];
    private const RANKS = ['import' => 10, 'ai' => 20, 'tmdb' => 30, 'manual' => 40];

    private $clock;

    public function __construct(callable $clock = null)
    {
        $this->clock = $clock ?: 'time';
    }

    public function apply(
        int $vodId,
        string $field,
        $value,
        string $source,
        string $sourceRef = '',
        bool $confirmed = false,
        bool $reviewedOverride = false
    ): bool {
        $this->assertInput($vodId, $field, $source, $sourceRef);
        $current = $this->loadState($vodId, $field);
        if (!$this->mayReplace($current, $source, $confirmed, $reviewedOverride)) {
            return false;
        }

        $this->persistValue($vodId, $field, $value);
        $this->persistState([
            'vod_id' => $vodId,
            'field_name' => $field,
            'source' => $source,
            'is_locked' => $source === 'manual' ? 1 : 0,
            'source_ref' => $sourceRef,
            'updated_at' => (int) call_user_func($this->clock),
        ]);
        return true;
    }

    public function mayReplace(?array $current, string $source, bool $confirmed = false, bool $reviewedOverride = false): bool
    {
        if (!isset(self::RANKS[$source])) {
            throw new InvalidArgumentException('Unsupported field source.');
        }
        if ($source === 'tmdb' && !$confirmed) {
            return false;
        }
        if ($current === null) {
            return true;
        }
        if (!empty($current['is_locked']) && $source !== 'manual' && !$reviewedOverride) {
            return false;
        }
        $currentSource = (string) ($current['source'] ?? 'import');
        if (!isset(self::RANKS[$currentSource])) {
            throw new InvalidArgumentException('Stored field source is invalid.');
        }
        return $reviewedOverride || self::RANKS[$source] >= self::RANKS[$currentSource];
    }

    public function inspect(int $vodId, string $field): array
    {
        $this->assertInput($vodId, $field, 'import', '');
        return ['value' => $this->loadValue($vodId, $field), 'state' => $this->loadState($vodId, $field)];
    }

    protected function loadState(int $vodId, string $field): ?array
    {
        $row = Db::name('vod_field_state')->where(['vod_id' => $vodId, 'field_name' => $field])->find();
        return $row ?: null;
    }

    protected function loadValue(int $vodId, string $field)
    {
        $table = in_array($field, self::EXT_FIELDS, true) ? 'vod_ext' : 'vod';
        return Db::name($table)->where('vod_id', $vodId)->value($field);
    }

    protected function persistValue(int $vodId, string $field, $value): void
    {
        $table = in_array($field, self::EXT_FIELDS, true) ? 'vod_ext' : 'vod';
        $affected = Db::name($table)->where('vod_id', $vodId)->update([$field => $value]);
        if ($affected === false) {
            throw new \RuntimeException('Governed field value could not be persisted.');
        }
    }

    protected function persistState(array $state): void
    {
        $existing = Db::name('vod_field_state')->where([
            'vod_id' => $state['vod_id'], 'field_name' => $state['field_name'],
        ])->find();
        if ($existing) {
            Db::name('vod_field_state')->where([
                'vod_id' => $state['vod_id'], 'field_name' => $state['field_name'],
            ])->update($state);
            return;
        }
        Db::name('vod_field_state')->insert($state);
    }

    private function assertInput(int $vodId, string $field, string $source, string $sourceRef): void
    {
        if ($vodId <= 0) {
            throw new InvalidArgumentException('Video ID must be positive.');
        }
        if (!in_array($field, array_merge(self::EXT_FIELDS, self::NATIVE_FIELDS), true)) {
            throw new InvalidArgumentException('Field is not governed.');
        }
        if (!isset(self::RANKS[$source])) {
            throw new InvalidArgumentException('Unsupported field source.');
        }
        if (strlen($sourceRef) > 191 || preg_match('/[\x00-\x1F\x7F]/', $sourceRef)) {
            throw new InvalidArgumentException('Invalid field source reference.');
        }
    }
}
