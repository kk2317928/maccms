<?php
declare(strict_types=1);

function activityFail($message) { fwrite(STDERR, "FAIL: ".$message.PHP_EOL); exit(1); }
function activityAssert($condition, $message) { if (!$condition) { activityFail($message); } }
function activitySame($expected, $actual, $message) {
    if ($expected !== $actual) { activityFail($message." expected=".var_export($expected,true)." actual=".var_export($actual,true)); }
}

$root=dirname(__DIR__,2);
foreach(array(
    'application/common/util/ApiV1ActivityMerge.php',
    'application/common/util/ApiV1ActivityRepository.php',
    'application/common/util/ApiV1ActivityDto.php',
    'application/common/util/ApiV1AuthContext.php',
    'application/api/controller/v1/Activity.php',
) as $file) { activityAssert(is_file($root.'/'.$file), 'Missing '.$file); }

require_once $root.'/application/common/util/ApiV1Dto.php';
require_once $root.'/application/common/util/ApiV1ActivityMerge.php';
require_once $root.'/application/common/util/ApiV1ActivityDto.php';
require_once $root.'/application/common/util/ApiV1Bootstrap.php';

use app\common\util\ApiV1ActivityMerge;
use app\common\util\ApiV1ActivityDto;
use app\common\util\ApiV1Bootstrap;

$payload=ApiV1ActivityMerge::normalize(array(
    'favorites'=>array(
        array('public_id'=>'ABC234','updated_at'=>1700000000),
        array('public_id'=>'ABC234','updated_at'=>1700000100),
    ),
    'progress'=>array(
        array('public_id'=>'ABC234','source_id'=>'s1','episode_id'=>'s1e2','position_seconds'=>180,'duration_seconds'=>1200,'updated_at'=>1700000200),
        array('public_id'=>'ABC234','source_id'=>'s1','episode_id'=>'s1e2','position_seconds'=>90,'duration_seconds'=>1200,'updated_at'=>1700000100),
    ),
));
activitySame(1,count($payload['favorites']),'Favorite merge must deduplicate by public ID.');
activitySame(1700000100,$payload['favorites'][0]['updated_at'],'Newest favorite timestamp must win.');
activitySame(1,count($payload['progress']),'Progress merge must deduplicate by video/source/episode.');
activitySame(180,$payload['progress'][0]['position_seconds'],'Newest progress record must win.');
activitySame(array('favorites'=>array(),'progress'=>array()),ApiV1ActivityMerge::normalize(array()),'Empty merge must be a no-op payload.');

foreach(array(
    array('favorites'=>array(array('public_id'=>'bad','updated_at'=>1))),
    array('progress'=>array(array('public_id'=>'ABC234','source_id'=>'','episode_id'=>'e','position_seconds'=>1,'duration_seconds'=>2,'updated_at'=>1))),
    array('progress'=>array(array('public_id'=>'ABC234','source_id'=>'s','episode_id'=>'e','position_seconds'=>3,'duration_seconds'=>2,'updated_at'=>1))),
    array('progress'=>array(array('public_id'=>'ABC234','source_id'=>'s1','episode_id'=>'s1e1','position_seconds'=>'4294967296','duration_seconds'=>'4294967296','updated_at'=>1))),
    array('favorites'=>array_fill(0,51,array('public_id'=>'ABC234','updated_at'=>1))),
) as $invalid) {
    try { ApiV1ActivityMerge::normalize($invalid); activityFail('Invalid merge payload accepted.'); }
    catch (InvalidArgumentException $expected) {}
}

$favorite=ApiV1ActivityDto::favorite(array('public_id'=>'ABC234','title'=>'Example','poster'=>'p.jpg','favorited_at'=>123));
activitySame(array('public_id'=>'ABC234','title'=>'Example','poster'=>'p.jpg','favorited_at'=>123),$favorite->toArray(),'Favorite DTO allowlist mismatch.');
$progress=ApiV1ActivityDto::progress(array('public_id'=>'ABC234','source_id'=>'s1','episode_id'=>'s1e2','position_seconds'=>33,'duration_seconds'=>99,'updated_at'=>456));
activityAssert(!array_key_exists('vod_id',$progress->toArray()),'Activity DTO must not expose vod_id.');
activityAssert(!array_key_exists('ulog_id',$progress->toArray()),'Activity DTO must not expose ulog_id.');

$routes=array(
    array('GET','/api/v1/me/favorites','/v1.activity/favorites'),
    array('PUT','/api/v1/me/favorites/ABC234','/v1.activity/favorite/public_id/ABC234'),
    array('DELETE','/api/v1/me/favorites/ABC234','/v1.activity/unfavorite/public_id/ABC234'),
    array('GET','/api/v1/me/history','/v1.activity/history'),
    array('GET','/api/v1/me/progress/ABC234','/v1.activity/progress/public_id/ABC234'),
    array('PUT','/api/v1/me/progress/ABC234','/v1.activity/saveProgress/public_id/ABC234'),
    array('DELETE','/api/v1/me/progress/ABC234','/v1.activity/deleteProgress/public_id/ABC234'),
    array('POST','/api/v1/me/activity/merge','/v1.activity/merge'),
);
foreach($routes as $route) {
    $resolved=ApiV1Bootstrap::resolve(array('REQUEST_METHOD'=>$route[0],'REQUEST_URI'=>$route[1],'SCRIPT_NAME'=>'/index.php'));
    activitySame($route[2],$resolved['path_info'],'Route mismatch '.$route[0].' '.$route[1]);
}
$wrong=ApiV1Bootstrap::resolve(array('REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/api/v1/me/favorites/ABC234','SCRIPT_NAME'=>'/index.php'));
activitySame('/v1.index/methodNotAllowed',$wrong['path_info'],'Favorite mutation must reject POST.');

$repository=file_get_contents($root.'/application/common/util/ApiV1ActivityRepository.php');
activityAssert(strpos($repository,'ApiV1CatalogRepository::PUBLICATION_SQL')!==false,'Activity queries must enforce published visibility.');
activityAssert(strpos($repository,"'ulog_type'=>2")!==false,'Favorites must reuse native ulog type 2.');
activityAssert(strpos($repository,"'ulog_type'=>4")!==false,'History/progress must reuse native ulog type 4.');
activityAssert(strpos($repository,'client_updated_at')!==false,'Merge must compare client and server timestamps.');
activityAssert(strpos($repository,'Db::transaction')!==false,'Anonymous merge must retain the transaction boundary for transactional deployments.');
activityAssert(strpos($repository,'GET_LOCK')!==false && strpos($repository,'RELEASE_LOCK')!==false,'MyISAM ulog writes must use an advisory lock.');
activityAssert(substr_count($repository,'ulog_duration')>=4,'Progress duration must be persisted and returned.');
activityAssert(strpos($repository,'VodPlaybackCodec::decode')!==false,'Progress must validate the published episode inventory.');
activityAssert(strpos($repository,'4294967295')!==false,'Progress fields must respect unsigned integer storage bounds.');
activityAssert(substr_count($repository,'ulog_points')>=2,'Progress operations must exclude paid entitlement rows.');
$controller=file_get_contents($root.'/application/api/controller/v1/Activity.php');
activityAssert(strpos($controller,"'UNAUTHENTICATED'")!==false,'Member activity must use the established authentication error code.');

fwrite(STDOUT,"API v1 member activity contract passed.".PHP_EOL);
