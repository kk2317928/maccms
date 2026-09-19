<?php

namespace app\common\util;

use RuntimeException;
use think\Cache;
use think\Db;
use Throwable;

final class FinalPublicationService
{
    private $workspace;
    private $audit;
    private $transaction;
    private $locker;
    private $nativeUpdater;
    private $extensionUpdater;
    private $clock;
    private $cacheInvalidator;
    private $searchSynchronizer;

    public function __construct(FinalPublicationWorkspace $workspace = null, ContentAdminAudit $audit = null, callable $transaction = null, callable $locker = null, callable $nativeUpdater = null, callable $extensionUpdater = null, callable $clock = null, callable $cacheInvalidator = null, callable $searchSynchronizer = null)
    {
        $this->workspace = $workspace ?: new FinalPublicationWorkspace();
        $this->audit = $audit ?: new ContentAdminAudit();
        $this->transaction = $transaction ?: static function (callable $callback) {
            self::assertTransactionalTables();
            Db::startTrans();
            try { $result = $callback(); Db::commit(); return $result; }
            catch (Throwable $exception) { Db::rollback(); throw $exception; }
        };
        $this->locker = $locker ?: static function (int $vodId): array {
            $video = Db::name('vod')->where('vod_id', $vodId)->lock(true)->find();
            $ext = Db::name('vod_ext')->where('vod_id', $vodId)->lock(true)->find();
            if (!$video || !$ext) { throw new RuntimeException('Publication candidate was not found.'); }
            $localeRows = Db::name('content_lang')->where(['content_type' => 'vod', 'content_id' => $vodId])->lock(true)->select();
            $locales = [];
            foreach ((array) $localeRows as $localeRow) {
                $data = json_decode((string) ($localeRow['data'] ?? ''), true);
                if (is_array($data)) { $locales[(string) $localeRow['lang_code']] = $data; }
            }
            $terms = Db::name('vod_meta_term')->alias('vmt')->join('__META_TERM__ mt', 'mt.term_id=vmt.term_id')
                ->field('mt.term_id,mt.kind,mt.slug,mt.name_tw,mt.name_cn,mt.name_en')
                ->where('vmt.vod_id', $vodId)->where('mt.status', 1)->order('mt.sort asc,mt.term_id asc')->lock(true)->select();
            return ['row' => array_merge($video, $ext), 'locales' => $locales, 'terms' => (array) $terms];
        };
        $this->nativeUpdater = $nativeUpdater ?: static function (int $vodId, array $update): bool {
            return Db::name('vod')->where('vod_id', $vodId)->update($update) !== false;
        };
        $this->extensionUpdater = $extensionUpdater ?: static function (int $vodId, array $update): bool {
            return Db::name('vod_ext')->where('vod_id', $vodId)->where('workflow_status', VodWorkflow::MANUAL_REVIEW)->update($update) === 1;
        };
        $this->clock = $clock ?: 'time';
        $this->cacheInvalidator = $cacheInvalidator ?: static function (int $vodId, string $publicId, array $row): void {
            $flag = (string) ($GLOBALS['config']['app']['cache_flag'] ?? '');
            $slug = (string) ($row['vod_en'] ?? '');
            Cache::rm($flag . '_vod_detail_' . $vodId . '_' . $slug);
            Cache::rm($flag . '_vod_detail_' . $vodId . '_');
            if ($slug !== '') { Cache::rm($flag . '_vod_detail_0_' . $slug); }
        };
        $this->searchSynchronizer = $searchSynchronizer ?: static function (int $vodId): void {
            MeilisearchSync::afterVodSave($vodId);
        };
    }

    public function publish(int $vodId, int $actorId, string $actorName, array $grants, bool $confirmed, string $expectedRevision): array
    {
        (new ContentAdminPolicy())->assertAllowed('publish', $grants, $confirmed);
        if (!preg_match('/^[a-f0-9]{64}$/', $expectedRevision)) { throw new RuntimeException('A valid publication revision is required.'); }
        $published = call_user_func($this->transaction, function () use ($vodId, $actorId, $actorName, $expectedRevision): array {
            $snapshot = call_user_func($this->locker, $vodId);
            $locked = isset($snapshot['row']) ? (array) $snapshot['row'] : (array) $snapshot;
            $locales = isset($snapshot['locales']) ? (array) $snapshot['locales'] : null;
            $terms = isset($snapshot['terms']) ? (array) $snapshot['terms'] : null;
            $preview = $this->workspace->previewRow($locked, $locales, $terms);
            if (!$preview['publishable']) { throw new RuntimeException('Publication validation failed.'); }
            if (!hash_equals($preview['revision'], $expectedRevision)) { throw new RuntimeException('Publication candidate changed after preview.'); }
            VodWorkflow::assertTransition((string) $locked['workflow_status'], VodWorkflow::PUBLISHED);
            $now = (int) call_user_func($this->clock);
            $nativeTaxonomy = [];
            foreach (['region' => 'vod_area', 'genre' => 'vod_class', 'tag' => 'vod_tag'] as $kind => $field) {
                $mapped = implode(',', array_values(array_filter(array_map(static function (array $term): string {
                    foreach (['name_tw', 'name_cn', 'name_en'] as $nameField) {
                        $label = trim((string) ($term[$nameField] ?? ''));
                        if ($label !== '') { return $label; }
                    }
                    return '';
                }, $preview['taxonomy'][$kind]))));
                $nativeTaxonomy[$field] = $mapped !== '' ? $mapped : (string) ($locked[$field] ?? '');
            }
            if (!call_user_func($this->nativeUpdater, $vodId, array_merge(['vod_status' => 1, 'vod_publish_time' => 0], $nativeTaxonomy))) {
                throw new RuntimeException('Native publication update failed.');
            }
            if (!call_user_func($this->extensionUpdater, $vodId, ['workflow_status' => VodWorkflow::PUBLISHED, 'published_at' => $now, 'updated_at' => $now])) {
                throw new RuntimeException('Workflow publication update failed.');
            }
            $before = ['workflow_status' => VodWorkflow::MANUAL_REVIEW, 'vod_status' => (int) ($locked['vod_status'] ?? 0), 'published_at' => (int) ($locked['published_at'] ?? 0)];
            $after = ['workflow_status' => VodWorkflow::PUBLISHED, 'vod_status' => 1, 'published_at' => $now];
            $this->audit->append($actorId, $actorName, 'content.publish', 'vod', (string) $locked['public_id'], $before, $after, [
                'vod_id' => $vodId, 'warning_count' => count($preview['warnings']), 'playback_source_count' => count($preview['playback']),
            ]);
            return ['vod_id' => $vodId, 'public_id' => (string) $locked['public_id'], 'workflow_status' => VodWorkflow::PUBLISHED, 'vod_status' => 1, 'published_at' => $now, '_row' => $locked];
        });
        call_user_func($this->cacheInvalidator, $vodId, $published['public_id'], $published['_row']);
        call_user_func($this->searchSynchronizer, $vodId);
        unset($published['_row']);
        return $published;
    }
    private static function assertTransactionalTables(): void
    {
        $prefix = (string) (function_exists('config') ? config('database.prefix') : '');
        foreach (['vod', 'vod_ext', 'content_lang', 'vod_meta_term', 'meta_term', 'content_admin_audit_event'] as $name) {
            $table = $prefix . $name;
            $escaped = str_replace(["\\", "'"], ["\\\\", "\\'"], $table);
            $rows = Db::query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $escaped . "' LIMIT 1");
            $engine = strtoupper((string) ($rows[0]['ENGINE'] ?? $rows[0]['engine'] ?? ''));
            if ($engine !== 'INNODB') {
                throw new RuntimeException('Atomic publication requires InnoDB storage for every locked table.');
            }
        }
    }

}
