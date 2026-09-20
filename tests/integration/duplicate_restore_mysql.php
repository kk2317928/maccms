<?php

declare(strict_types=1);

$database = (string) getenv('MACCMS_TEST_DATABASE');
if (!preg_match('/^maccms_ci_[a-z0-9_]+$/', $database)) { fwrite(STDERR, "FAIL: disposable database required\n"); exit(1); }
$root = dirname(__DIR__, 2);
define('APP_PATH', $root . '/application/');
define('ENTRANCE', 'command');
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/duplicate-restore';
require $root . '/thinkphp/base.php';
\think\App::initCommon();

function duplicate_mysql_assert($condition, string $message): void
{
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$now = 1726704200;
$primaryId = (int) \think\Db::name('vod')->insertGetId(['vod_name' => 'Primary', 'vod_play_from' => 'main', 'vod_play_url' => '1$https://ci.test/1']);
$secondaryId = (int) \think\Db::name('vod')->insertGetId(['vod_name' => 'Secondary Alias', 'vod_en' => 'Secondary EN', 'vod_play_from' => 'main', 'vod_play_url' => '2$https://ci.test/2']);
\think\Db::name('vod_ext')->insert(['vod_id' => $primaryId, 'public_id' => 'ABC234', 'old_titles_json' => '["Primary Old"]', 'workflow_status' => 'duplicate_review', 'created_at' => $now, 'updated_at' => $now]);
\think\Db::name('vod_ext')->insert(['vod_id' => $secondaryId, 'public_id' => 'ABC235', 'title_tw' => '次要標題', 'old_titles_json' => '["Secondary Old"]', 'workflow_status' => 'duplicate_review', 'created_at' => $now, 'updated_at' => $now]);
$term1 = (int) \think\Db::name('meta_term')->insertGetId(['kind' => 'genre', 'slug' => 'ci-primary-' . $primaryId, 'name_tw' => '主分類', 'created_at' => $now, 'updated_at' => $now]);
$term2 = (int) \think\Db::name('meta_term')->insertGetId(['kind' => 'genre', 'slug' => 'ci-secondary-' . $secondaryId, 'name_tw' => '次分類', 'created_at' => $now, 'updated_at' => $now]);
\think\Db::name('vod_meta_term')->insertAll([['vod_id' => $primaryId, 'term_id' => $term1, 'created_at' => $now], ['vod_id' => $secondaryId, 'term_id' => $term2, 'created_at' => $now]]);
\think\Db::name('vod_field_state')->insertAll([
    ['vod_id' => $primaryId, 'field_name' => 'vod_name', 'source' => 'manual', 'is_locked' => 1, 'source_ref' => 'primary', 'updated_at' => $now],
    ['vod_id' => $secondaryId, 'field_name' => 'vod_name', 'source' => 'ai', 'is_locked' => 0, 'source_ref' => 'conflict', 'updated_at' => $now],
    ['vod_id' => $secondaryId, 'field_name' => 'vod_en', 'source' => 'tmdb', 'is_locked' => 0, 'source_ref' => 'move', 'updated_at' => $now],
]);
\think\Db::name('content_lang')->insertAll([
    ['content_type' => 'vod', 'content_id' => $primaryId, 'lang_code' => 'en', 'data' => '{"vod_name":"Primary"}', 'status' => 1, 'source' => 'manual', 'update_time' => $now],
    ['content_type' => 'vod', 'content_id' => $secondaryId, 'lang_code' => 'en', 'data' => '{"vod_name":"Conflict"}', 'status' => 0, 'source' => 'mt', 'update_time' => $now],
    ['content_type' => 'vod', 'content_id' => $secondaryId, 'lang_code' => 'zh-tw', 'data' => '{"vod_name":"次要"}', 'status' => 1, 'source' => 'manual', 'update_time' => $now],
]);
$item = 'ci-' . $primaryId;
\think\Db::name('ext_source_map')->insertAll([
    ['provider_code' => 'tmdb', 'item_key' => $item, 'cms_mid' => 1, 'cms_id' => $primaryId, 'map_confidence' => 1, 'map_time_add' => $now, 'map_time_update' => $now],
    ['provider_code' => 'tmdb', 'item_key' => $item, 'cms_mid' => 1, 'cms_id' => $secondaryId, 'map_confidence' => 0.5, 'map_time_add' => $now, 'map_time_update' => $now],
    ['provider_code' => 'tmdb', 'item_key' => $item . '-other', 'cms_mid' => 1, 'cms_id' => $secondaryId, 'map_confidence' => 0.8, 'map_time_add' => $now, 'map_time_update' => $now],
]);
$candidateId = (int) \think\Db::name('content_duplicate_candidate')->insertGetId(['vod_id_low' => min($primaryId, $secondaryId), 'vod_id_high' => max($primaryId, $secondaryId), 'evidence_json' => '[]', 'score' => 100, 'decision' => 'pending', 'created_at' => $now, 'updated_at' => $now]);

$merge = new \app\common\util\DuplicateMergeService(static function () use ($now): int { return $now + 1; });
$snapshotId = $merge->merge($candidateId, $primaryId, $secondaryId, 99);
$snapshot = \think\Db::name('content_merge_snapshot')->where('merge_snapshot_id', $snapshotId)->find();
$payload = json_decode((string) $snapshot['snapshot_json'], true);
duplicate_mysql_assert((int) $payload['version'] === 2 && isset($payload['merged_projection']), 'merge must persist a v2 projection snapshot.');
duplicate_mysql_assert((int) \think\Db::name('vod_meta_term')->where('vod_id', $primaryId)->count() === 2, 'taxonomy union was not moved to primary.');
duplicate_mysql_assert((int) \think\Db::name('content_lang')->where(['content_type' => 'vod', 'content_id' => $primaryId])->count() === 2, 'missing locale was not moved to primary.');
duplicate_mysql_assert((int) \think\Db::name('ext_source_map')->where(['cms_mid' => 1, 'cms_id' => $secondaryId])->count() === 1, 'external-map conflict was not retained on secondary.');

$restore = new \app\common\util\DuplicateRestoreService(static function () use ($now): int { return $now + 2; });
$restore->restore($snapshotId, 55, true, static function (int $reviewerId, string $permission): bool { return $reviewerId === 55 && $permission === 'content_duplicate_restore'; });
duplicate_mysql_assert((int) \think\Db::name('vod_meta_term')->where('vod_id', $primaryId)->count() === 1 && (int) \think\Db::name('vod_meta_term')->where('vod_id', $secondaryId)->count() === 1, 'taxonomy rows were not restored exactly.');
duplicate_mysql_assert((int) \think\Db::name('content_lang')->where(['content_type' => 'vod', 'content_id' => $secondaryId])->count() === 2, 'language rows were not restored exactly.');
duplicate_mysql_assert((int) \think\Db::name('ext_source_map')->where(['cms_mid' => 1, 'cms_id' => $secondaryId])->count() === 2, 'external maps were not restored exactly.');
duplicate_mysql_assert((string) \think\Db::name('content_merge_snapshot')->where('merge_snapshot_id', $snapshotId)->value('status') === 'restored', 'restore audit status was not persisted.');
fwrite(STDOUT, "OK: reversible relational duplicate merge passed on {$database}\n");
