<?php

declare(strict_types=1);

$database = (string) getenv('MACCMS_TEST_DATABASE');
if (!preg_match('/^maccms_ci_[a-z0-9_]+$/', $database)) {
    fwrite(STDERR, "FAIL: MACCMS_TEST_DATABASE must name a disposable maccms_ci_ database.\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
define('APP_PATH', $root . '/application/');
define('ENTRANCE', 'command');
$_SERVER['HTTP_USER_AGENT'] = 'maccms-native-path-ci';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/tests/integration/native_vod_paths.php';
require $root . '/thinkphp/base.php';
\think\App::initCommon();
// Mirror the native admin request module so model()/validate() fall back to
// app\common instead of looking for the nonexistent app\model namespace.
\think\Request::instance()->module('admin');

function native_fail(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function native_assert($condition, string $message): void
{
    if (!$condition) {
        native_fail($message);
    }
}

function native_playback_round_trip(array $row): void
{
    $decoded = \app\common\util\VodPlaybackCodec::decode(
        (string) $row['vod_play_from'],
        (string) $row['vod_play_url'],
        (string) $row['vod_play_server'],
        (string) $row['vod_play_note']
    );
    $encoded = \app\common\util\VodPlaybackCodec::encode($decoded);
    native_assert($encoded['from'] === $row['vod_play_from'], 'vod_play_from did not round-trip byte-for-byte.');
    native_assert($encoded['url'] === $row['vod_play_url'], 'vod_play_url did not round-trip byte-for-byte.');
    native_assert($encoded['server'] === $row['vod_play_server'], 'vod_play_server did not round-trip byte-for-byte.');
    native_assert($encoded['note'] === $row['vod_play_note'], 'vod_play_note did not round-trip byte-for-byte.');
}

$versionRow = \think\Db::query('SELECT VERSION() AS version');
$mysqlVersion = (string) ($versionRow[0]['version'] ?? '');
native_assert(strpos($mysqlVersion, '5.7.') === 0, 'native path workflow requires MySQL 5.7, got ' . $mysqlVersion . '.');

$maccms = config('maccms');
$maccms['app']['vod_search_optimise'] = '';
$maccms['collect']['vod'] = array_merge($maccms['collect']['vod'], [
    'status' => '3',
    'hits_start' => '0',
    'hits_end' => '0',
    'updown_start' => '0',
    'updown_end' => '0',
    'score' => '0',
    'pic' => '0',
    'tag' => '0',
    'psename' => '0',
    'psernd' => '0',
    'psesyn' => '0',
    'pseplayer' => '0',
    'psearea' => '0',
    'pselang' => '0',
    'inrule' => ',a',
    'uprule' => ',a,d',
    'filter' => '',
    'namewords' => '',
    'thesaurus' => '',
    'words' => '',
    'urlrole' => '0',
]);
config('maccms', $maccms);
$GLOBALS['config'] = $maccms;

\think\Db::name('type')->insert([
    'type_id' => 1,
    'type_name' => 'CI Video',
    'type_en' => 'ci-video',
    'type_sort' => 1,
    'type_mid' => 1,
    'type_pid' => 0,
    'type_status' => 1,
    'type_extend' => '{}',
]);
$type = \think\Db::name('type')->where('type_id', 1)->find();
\think\Cache::set($maccms['app']['cache_flag'] . '_type_list', [1 => $type]);

$adminPlayback = [
    'from' => ['dplayer', 'link'],
    'url' => [
        'Episode 1$https://media.invalid/admin-1.m3u8#Episode 2$https://media.invalid/admin-2.m3u8',
        'Trailer$https://media.invalid/admin-trailer.mp4',
    ],
    'server' => ['edge-a', ''],
    'note' => ['Primary', 'Trailer'],
];
$adminResult = model('Vod')->saveData([
    'vod_id' => 0,
    'type_id' => 1,
    'vod_name' => 'CI Admin Draft',
    'vod_en' => 'ci-admin-draft',
    'vod_letter' => 'C',
    'vod_status' => 3,
    'vod_content' => 'Admin native path fixture',
    'vod_blurb' => '',
    'vod_play_from' => $adminPlayback['from'],
    'vod_play_url' => $adminPlayback['url'],
    'vod_play_server' => $adminPlayback['server'],
    'vod_play_note' => $adminPlayback['note'],
    'vod_down_from' => [],
    'vod_down_url' => [],
    'vod_down_server' => [],
    'vod_down_note' => [],
    'vod_pic_screenshot' => '',
    'uptime' => 0,
    'uptag' => 0,
]);
native_assert($adminResult['code'] === 1 && (int) $adminResult['vod_id'] > 0, 'Vod::saveData admin path failed.');
$adminId = (int) $adminResult['vod_id'];

$collectRow = [
    'type_id' => 1,
    'type_name' => 'CI Video',
    'vod_name' => 'CI Collection Draft',
    'vod_en' => 'ci-collection-draft',
    'vod_letter' => 'C',
    'vod_status' => 3,
    'vod_lock' => 0,
    'vod_year' => 2026,
    'vod_level' => 0,
    'vod_hits' => 0,
    'vod_hits_day' => 0,
    'vod_hits_week' => 0,
    'vod_hits_month' => 0,
    'vod_stint_play' => 0,
    'vod_stint_down' => 0,
    'vod_total' => 2,
    'vod_serial' => 2,
    'vod_isend' => 0,
    'vod_up' => 0,
    'vod_down' => 0,
    'vod_score' => 0,
    'vod_score_all' => 0,
    'vod_score_num' => 0,
    'vod_class' => '',
    'vod_tag' => '',
    'vod_actor' => '',
    'vod_director' => '',
    'vod_content' => 'Collection native path fixture',
    'vod_blurb' => '',
    'vod_remarks' => 'Collected 2 episodes',
    'vod_pic' => '',
    'vod_play_from' => 'dplayer',
    'vod_play_url' => 'Episode 1$https://media.invalid/collect-1.m3u8#Episode 2$https://media.invalid/collect-2.m3u8',
    'vod_play_server' => 'edge-c',
    'vod_play_note' => 'Collected',
    'vod_down_from' => '',
    'vod_down_url' => '',
    'vod_down_server' => '',
    'vod_down_note' => '',
    'vod_plot_name' => '',
    'vod_plot_detail' => '',
    'vod_time_add' => time(),
    'vod_time_update' => time(),
    'vod_area' => '',
    'vod_lang' => '',
    'vod_douban_id' => 0,
];
$collectParam = [
    'sync_pic_opt' => 0,
    'filter_year' => '',
    'filter' => 0,
    'filter_from' => '',
    'opt' => 0,
];
$collectData = [
    'page' => ['page' => 1, 'pagecount' => 1, 'url' => 'fixture://collection'],
    'data' => [$collectRow],
];
$collectResult = model('Collect')->vod_data($collectParam, $collectData, 0);
native_assert($collectResult['code'] === 1, 'Collect::vod_data insert path failed: ' . ($collectResult['msg'] ?? 'unknown'));

$collectRecord = \think\Db::name('vod')->where('vod_name', 'CI Collection Draft')->find();
native_assert(!empty($collectRecord['vod_id']), 'Collection insert did not create a native video row.');
$collectId = (int) $collectRecord['vod_id'];
native_assert($collectId !== $adminId, 'Admin and collection writes must create distinct videos.');

$collectRow['vod_remarks'] = 'Collected 3 episodes';
$collectRow['vod_play_url'] .= '#Episode 3$https://media.invalid/collect-3.m3u8';
$collectData['data'] = [$collectRow];
$updateResult = model('Collect')->vod_data($collectParam, $collectData, 0);
native_assert($updateResult['code'] === 1, 'Collect::vod_data update path failed: ' . ($updateResult['msg'] ?? 'unknown'));
native_assert(\think\Db::name('vod')->where('vod_name', 'CI Collection Draft')->count() === 1, 'Collection update duplicated the native video.');

$extensions = \think\Db::name('vod_ext')->where('vod_id', 'in', [$adminId, $collectId])->order('vod_id asc')->select();
native_assert(count($extensions) === 2, 'Each native write path must create exactly one extension row.');
$publicIds = array_column($extensions, 'public_id');
native_assert(count(array_unique($publicIds)) === 2, 'Native write paths must receive distinct public IDs.');
foreach ($publicIds as $publicId) {
    native_assert((bool) preg_match('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}$/', $publicId), 'Invalid six-character public ID.');
}

$adminRow = \think\Db::name('vod')->where('vod_id', $adminId)->find();
$collectRowAfterUpdate = \think\Db::name('vod')->where('vod_id', $collectId)->find();
native_playback_round_trip($adminRow);
native_playback_round_trip($collectRowAfterUpdate);
native_assert($collectRowAfterUpdate['vod_remarks'] === 'Collected 3 episodes', 'Collection update did not persist the changed remarks.');

fwrite(STDOUT, "OK: native admin/collection writes and playback round trips passed for {$database} on MySQL {$mysqlVersion}.\n");
