<?php

declare(strict_types=1);

$database = (string) getenv('MACCMS_TEST_DATABASE');
if (!preg_match('/^maccms_ci_[a-z0-9_]+$/', $database)) { fwrite(STDERR, "FAIL: disposable database required\n"); exit(1); }
$root = dirname(__DIR__, 2);
define('APP_PATH', $root . '/application/');
define('ENTRANCE', 'command');
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/duplicate-indexes';
require $root . '/thinkphp/base.php';
\think\App::initCommon();

$expected = [
    'mac_vod' => ['idx_dup_name_year', 'idx_dup_en_year'],
    'mac_vod_ext' => ['idx_dup_tmdb_id', 'idx_dup_title_tw', 'idx_dup_original_title'],
];
foreach ($expected as $table => $indexes) {
    $rows = \think\Db::query('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?', [$database, $table]);
    $actual = array_column($rows, 'INDEX_NAME');
    foreach ($indexes as $index) {
        if (!in_array($index, $actual, true)) { fwrite(STDERR, "FAIL: missing {$table}.{$index}\n"); exit(1); }
    }
}
fwrite(STDOUT, "OK: duplicate lookup indexes exist on {$database}\n");
