<?php
declare(strict_types=1);

function catalogFail($message) { fwrite(STDERR, "FAIL: ".$message.PHP_EOL); exit(1); }
function catalogSame($expected, $actual, $message) { if ($expected !== $actual) { catalogFail($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)); } }
function catalogTrue($value, $message) { if (!$value) { catalogFail($message); } }

$root = dirname(__DIR__, 2);
$required = array(
    'application/common/util/ApiV1CatalogQuery.php',
    'application/common/util/ApiV1VideoDto.php',
    'application/common/util/ApiV1EpisodeDto.php',
    'application/common/util/ApiV1CatalogRepository.php',
    'application/common/util/ApiV1CatalogService.php',
    'application/api/controller/v1/Catalog.php',
);
foreach ($required as $relative) { catalogTrue(is_file($root.'/'.$relative), 'Missing T-071 file: '.$relative); }

require_once $root.'/application/common/util/ApiV1Dto.php';
require_once $root.'/application/common/util/ApiV1Pagination.php';
require_once $root.'/application/common/util/ApiV1Bootstrap.php';
require_once $root.'/application/common/util/VodPlaybackCodec.php';
require_once $root.'/application/common/util/ApiV1CatalogQuery.php';
require_once $root.'/application/common/util/ApiV1VideoDto.php';
require_once $root.'/application/common/util/ApiV1EpisodeDto.php';
require_once $root.'/application/common/util/ApiV1CatalogRepository.php';

use app\common\util\ApiV1Bootstrap;
use app\common\util\ApiV1CatalogQuery;
use app\common\util\ApiV1CatalogRepository;
use app\common\util\ApiV1EpisodeDto;
use app\common\util\ApiV1VideoDto;

$routes = array(
    '/api/v1/home' => '/v1.catalog/home',
    '/api/v1/videos' => '/v1.catalog/videos',
    '/api/v1/videos/ABC234' => '/v1.catalog/detail/public_id/ABC234',
    '/api/v1/videos/ABC234/episodes' => '/v1.catalog/episodes/public_id/ABC234',
    '/api/v1/search' => '/v1.catalog/search',
    '/api/v1/taxonomies' => '/v1.catalog/taxonomies',
);
foreach ($routes as $path => $target) {
    catalogSame(array('module'=>'api','path_info'=>$target), ApiV1Bootstrap::resolve(array('REQUEST_METHOD'=>'GET','REQUEST_URI'=>$path,'SCRIPT_NAME'=>'/index.php')), 'Route mismatch: '.$path);
    catalogSame(array('module'=>'api','path_info'=>'/v1.index/methodNotAllowed'), ApiV1Bootstrap::resolve(array('REQUEST_METHOD'=>'POST','REQUEST_URI'=>$path,'SCRIPT_NAME'=>'/index.php')), 'Known non-GET route must be 405.');
}

$query = ApiV1CatalogQuery::fromArray(array('sort'=>'popular','type2'=>'movie','year'=>'2026','taxonomy_kind'=>'genre','taxonomy_slug'=>'sci-fi'));
catalogSame(array('sort'=>'popular','type2'=>'movie','year'=>2026,'taxonomy_kind'=>'genre','taxonomy_slug'=>'sci-fi'), $query->toArray(), 'Catalog filter normalization mismatch.');
foreach (array(
    array('sort'=>'secret'),
    array('year'=>'20x6'),
    array('taxonomy_kind'=>'person'),
    array('taxonomy_slug'=>'Bad Slug'),
) as $invalid) {
    try { ApiV1CatalogQuery::fromArray($invalid); catalogFail('Expected rejected filters.'); }
    catch (InvalidArgumentException $exception) { catalogTrue(in_array($exception->getMessage(), array_keys($invalid), true), 'Only safe field names may escape validation.'); }
}
catalogSame('matrix', ApiV1CatalogQuery::searchTerm(array('q'=>' matrix ')), 'Search must trim q.');
try { ApiV1CatalogQuery::searchTerm(array('q'=>str_repeat('x', 101))); catalogFail('Expected overlong q rejection.'); }
catch (InvalidArgumentException $exception) { catalogSame('q', $exception->getMessage(), 'Search error must name q only.'); }

$row = array(
    'vod_id'=>99,'public_id'=>'ABC234','vod_name'=>'Native','title_tw'=>'繁體','title_cn'=>'简体','title_en'=>'English',
    'original_title'=>'Original','vod_pic'=>'poster.jpg','vod_year'=>'2026','vod_remarks'=>'更新中','vod_score'=>'8.5',
    'published_at'=>123,'vod_content'=>'<p>Synopsis</p>','vod_actor'=>'Actor','vod_director'=>'Director','vod_area'=>'TW',
    'vod_lang'=>'zh','vod_serial'=>'3','vod_total'=>'10','vod_isend'=>0,'trailer_url'=>'trailer','preview_url'=>'preview',
    'merged_into_vod_id'=>0,'workflow_status'=>'published','vod_status'=>1,'secret'=>'never',
);
$summary = ApiV1VideoDto::summary($row)->toArray();
catalogSame(array('public_id','title','titles','poster','year','remarks','score','published_at'), array_keys($summary), 'Summary DTO fields changed.');
catalogTrue(strpos(json_encode($summary), 'vod_id') === false && strpos(json_encode($summary), 'secret') === false, 'Summary leaked internal fields.');
catalogSame('繁體', $summary['title'], 'Traditional Chinese title must be initial display title.');

$decoded = array(array('source'=>'m3u8','server'=>'secret-host','note'=>'vip','episodes'=>array(
    array('name'=>'第1集','url'=>'https://secret/1.m3u8?token=x','format'=>'named'),
    array('name'=>'','url'=>'https://secret/2.m3u8','format'=>'url_only'),
)));
$episodes = ApiV1EpisodeDto::collection('ABC234', $decoded);
catalogSame('ABC234', $episodes['public_id'], 'Episode collection public ID mismatch.');
catalogSame(array('source_id','name','position','episodes'), array_keys($episodes['sources'][0]), 'Source DTO fields changed.');
catalogSame(array('episode_id','name','position'), array_keys($episodes['sources'][0]['episodes'][0]), 'Episode DTO fields changed.');
$encodedEpisodes = json_encode($episodes);
foreach (array('https://','token=','secret-host','vip','format','url') as $forbidden) {
    catalogTrue(strpos($encodedEpisodes, $forbidden) === false, 'Episode DTO leaked forbidden playback data: '.$forbidden);
}

$predicate = ApiV1CatalogRepository::PUBLICATION_SQL;
foreach (array('vod_status','workflow_status','published','merged_into_vod_id','published_at') as $needle) {
    catalogTrue(strpos($predicate, $needle) !== false, 'Publication predicate missing '.$needle);
}
$repositorySource = file_get_contents($root.'/application/common/util/ApiV1CatalogRepository.php');
$episodeBoundary = strpos($repositorySource, 'function findEpisodeData');
catalogTrue($episodeBoundary !== false, 'Episode repository boundary is missing.');
catalogTrue(strpos(substr($repositorySource, 0, $episodeBoundary), 'vod_play_url') === false, 'Catalog repository must not select raw playback URLs outside the episode fetch boundary.');
$episodeMethod = substr($repositorySource, $episodeBoundary);
catalogTrue(strpos($episodeMethod, 'field(') !== false, 'Episode query must explicitly select playback columns.');

fwrite(STDOUT, "API v1 public catalog contract passed.".PHP_EOL);
