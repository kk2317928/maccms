<?php

declare(strict_types=1);

$database = (string) getenv('MACCMS_TEST_DATABASE');
if (!preg_match('/^maccms_ci_[a-z0-9_]+$/', $database)) {
    fwrite(STDERR, "FAIL: disposable database required\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
define('APP_PATH', $root . '/application/');
define('ENTRANCE', 'command');
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/taxonomy-dictionary';
require $root . '/thinkphp/base.php';
\think\App::initCommon();

$prefix = 'ci-dict-' . str_replace('.', '-', (string) microtime(true));
\think\Db::name('meta_term')->where('slug', 'like', $prefix . '%')->delete();

$service = new \app\common\util\TaxonomyDictionaryService(static fn(): int => 1700000000);
$genre = $service->save([
    'kind' => 'genre',
    'slug' => $prefix . '-science-fiction',
    'name_tw' => '科幻 CI ' . $prefix,
    'name_cn' => '',
    'name_en' => 'Science Fiction CI ' . $prefix,
    'synonyms' => ['Sci-Fi-' . $prefix],
    'status' => 1,
    'sort' => 9,
], 1);

$termId = (int) $genre['term_id'];
if ($termId <= 0 || \think\Db::name('meta_term')->where('term_id', $termId)->count() !== 1) {
    failDictionaryMysql('dictionary term was not persisted');
}

try {
    $service->save([
        'kind' => 'genre',
        'slug' => $prefix . '-conflict',
        'name_tw' => '另一詞條 ' . $prefix,
        'name_cn' => '',
        'name_en' => '',
        'synonyms' => ['sci-fi-' . $prefix],
        'status' => 1,
        'sort' => 10,
    ], 1);
    failDictionaryMysql('case-folded synonym collision was accepted');
} catch (InvalidArgumentException $exception) {
}

$region = $service->save([
    'kind' => 'region',
    'slug' => $prefix . '-region',
    'name_tw' => '地區 CI ' . $prefix,
    'name_cn' => '',
    'name_en' => '',
    'synonyms' => ['Sci-Fi-' . $prefix],
    'status' => 1,
    'sort' => 1,
], 1);
if ((int) $region['term_id'] <= 0) {
    failDictionaryMysql('same synonym in another kind was rejected');
}

try {
    \think\Db::name('meta_term')->insert([
        'kind' => 'genre',
        'slug' => $prefix . '-science-fiction',
        'name_tw' => '重複 slug',
        'name_cn' => '',
        'name_en' => '',
        'synonyms_json' => '[]',
        'status' => 1,
        'sort' => 0,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    failDictionaryMysql('database kind/slug uniqueness was not enforced');
} catch (Throwable $exception) {
}

$vodId = 992001;
\think\Db::name('vod_meta_term')->where(['vod_id' => $vodId, 'term_id' => $termId])->delete();
\think\Db::name('vod_meta_term')->insert(['vod_id' => $vodId, 'term_id' => $termId, 'created_at' => time()]);
$deleted = $service->delete($termId, 1);
if (($deleted['action'] ?? '') !== 'deactivated') {
    failDictionaryMysql('referenced term was not deactivated');
}
$row = \think\Db::name('meta_term')->where('term_id', $termId)->find();
if (!$row || (int) $row['status'] !== 0 || \think\Db::name('vod_meta_term')->where(['vod_id' => $vodId, 'term_id' => $termId])->count() !== 1) {
    failDictionaryMysql('deactivation did not preserve referenced relation');
}

$regionId = (int) $region['term_id'];
$removed = $service->delete($regionId, 1);
if (($removed['action'] ?? '') !== 'deleted' || \think\Db::name('meta_term')->where('term_id', $regionId)->count() !== 0) {
    failDictionaryMysql('unreferenced term was not deleted');
}

fwrite(STDOUT, "OK: taxonomy dictionary passed on {$database}\n");

function failDictionaryMysql(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}
