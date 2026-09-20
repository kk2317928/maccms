<?php
declare(strict_types=1);

function openapiFail($message){fwrite(STDERR,"FAIL: ".$message.PHP_EOL);exit(1);}
function openapiAssert($condition,$message){if(!$condition)openapiFail($message);}
function openapiSame($expected,$actual,$message){if($expected!==$actual)openapiFail($message." expected=".var_export($expected,true)." actual=".var_export($actual,true));}

$root=dirname(__DIR__,2);
foreach(array(
 'docs/api/v1/openapi.json',
 'application/common/util/ApiV1OpenApi.php',
 'application/api/controller/v1/Docs.php',
) as $file) openapiAssert(is_file($root.'/'.$file),'Missing '.$file);

require_once $root.'/application/common/util/ApiV1Bootstrap.php';
require_once $root.'/application/common/util/ApiV1OpenApi.php';

use app\common\util\ApiV1Bootstrap;
use app\common\util\ApiV1OpenApi;

$raw=ApiV1OpenApi::raw($root.'/docs/api/v1/openapi.json');
openapiAssert(strpos($raw,'"data": {}')!==false,'Raw OpenAPI must preserve empty schema objects.');
$document=ApiV1OpenApi::document($root.'/docs/api/v1/openapi.json');
openapiSame('3.0.3',$document['openapi']??null,'OpenAPI version mismatch.');
openapiSame('/api/v1',$document['servers'][0]['url']??null,'OpenAPI server base mismatch.');

$expected=array(
 '/'=>array('get'),
 '/home'=>array('get'),
 '/videos'=>array('get'),
 '/videos/{public_id}'=>array('get'),
 '/videos/{public_id}/episodes'=>array('get'),
 '/videos/{public_id}/playback/{source_id}/{episode_id}'=>array('get'),
 '/search'=>array('get'),
 '/taxonomies'=>array('get'),
 '/auth/login'=>array('post'),
 '/auth/refresh'=>array('post'),
 '/auth/logout'=>array('post'),
 '/auth/sessions'=>array('get'),
 '/auth/sessions/{session_id}'=>array('delete'),
 '/me/favorites'=>array('get'),
 '/me/favorites/{public_id}'=>array('put','delete'),
 '/me/history'=>array('get'),
 '/me/progress/{public_id}'=>array('get','put','delete'),
 '/me/activity/merge'=>array('post'),
 '/openapi.json'=>array('get'),
 '/events'=>array('post'),
 '/rankings'=>array('get'),
 '/videos/{public_id}/recommendations'=>array('get'),
);
foreach($expected as $path=>$methods){
 openapiAssert(isset($document['paths'][$path])&&is_array($document['paths'][$path]),'Undocumented v1 path '.$path);
 $actual=array_values(array_intersect(array('get','post','put','delete','patch'),array_keys($document['paths'][$path])));
 sort($actual);sort($methods);
 openapiSame($methods,$actual,'HTTP methods mismatch for '.$path);
 foreach($methods as $method){
  $operation=$document['paths'][$path][$method];
  openapiAssert(!empty($operation['operationId']),'Missing operationId for '.$method.' '.$path);
  openapiAssert(isset($operation['responses']['200'])||isset($operation['responses']['201'])||isset($operation['responses']['202'])||isset($operation['responses']['204']),'Missing success response for '.$method.' '.$path);
 }
}
openapiSame(count($expected),count($document['paths']),'Unexpected or missing v1 path.');

$security=$document['components']['securitySchemes']['bearerAuth']??array();
openapiSame('http',$security['type']??null,'Bearer security type mismatch.');
openapiSame('bearer',$security['scheme']??null,'Bearer scheme mismatch.');
openapiAssert(empty($document['paths']['/auth/login']['post']['security']),'Login must be public.');
openapiSame(array(array('bearerAuth'=>array())),$document['paths']['/me/history']['get']['security']??null,'Member endpoint security mismatch.');

foreach(array('Envelope','ErrorEnvelope','VideoSummary','VideoDetail','EpisodeCollection','Playback','Favorite','History','Progress','TokenResponse') as $schema){
 openapiAssert(isset($document['components']['schemas'][$schema]),'Missing schema '.$schema);
}
$summaryProps=array_keys($document['components']['schemas']['VideoSummary']['properties']??array());
openapiSame(array('public_id','title','titles','poster','year','remarks','score','published_at'),$summaryProps,'VideoSummary schema drift.');
$playbackProps=array_keys($document['components']['schemas']['Playback']['properties']??array());
openapiSame(array('public_id','source_id','episode_id','url','expires_at'),$playbackProps,'Playback schema drift.');

$mergeSchema=$document['paths']['/me/activity/merge']['post']['requestBody']['content']['application/json']['schema']??array();
openapiSame(array('favorites','progress'),array_keys($mergeSchema['properties']??array()),'Anonymous merge request fields mismatch.');
openapiSame(array('public_id','source_id','episode_id','position_seconds','duration_seconds','updated_at'),$mergeSchema['properties']['progress']['items']['required']??null,'Anonymous progress requirements mismatch.');

$detailSchema=$document['components']['schemas']['VideoDetail']??array();
openapiAssert(!isset($detailSchema['allOf']),'Closed VideoDetail schema must be flattened.');
openapiSame(array_merge($summaryProps,array('synopsis','actors','directors','area','language','episode','trailer_url','preview_url','taxonomies')),array_keys($detailSchema['properties']??array()),'VideoDetail schema fields mismatch.');

$operationSchemas=array(
 '/videos'=>'#/components/schemas/VideoSummaryCollectionResponse',
 '/videos/{public_id}'=>'#/components/schemas/VideoDetailResponse',
 '/videos/{public_id}/episodes'=>'#/components/schemas/EpisodeCollectionResponse',
 '/videos/{public_id}/playback/{source_id}/{episode_id}'=>'#/components/schemas/PlaybackResponse',
 '/auth/login'=>'#/components/schemas/TokenEnvelope',
 '/me/favorites'=>'#/components/schemas/FavoriteCollectionResponse',
 '/me/history'=>'#/components/schemas/HistoryCollectionResponse',
 '/me/progress/{public_id}'=>'#/components/schemas/ProgressResponse',
);
foreach($operationSchemas as $path=>$ref){
 $method=$path==='/auth/login'?'post':'get';
 $status=$path==='/auth/login'?'201':'200';
 $actual=$document['paths'][$path][$method]['responses'][$status]['content']['application/json']['schema']['$ref']??null;
 openapiSame($ref,$actual,'Typed response mismatch for '.$path);
}
foreach(array('/home','/videos','/videos/{public_id}','/videos/{public_id}/episodes','/search','/taxonomies','/openapi.json') as $path){
 openapiAssert(isset($document['paths'][$path]['get']['responses']['304']),'Missing 304 response for '.$path);
}
foreach(array('/videos/{public_id}','/videos/{public_id}/episodes','/videos/{public_id}/playback/{source_id}/{episode_id}') as $path){
 openapiAssert(isset($document['paths'][$path]['get']['responses']['308']),'Missing canonical 308 response for '.$path);
 openapiAssert(isset($document['paths'][$path]['get']['responses']['308']['headers']['Location']),'Canonical redirect must document Location.');
}

$encoded=json_encode($document);
foreach(array('vod_id','user_id','token_hash','vod_play_url','merged_into_vod_id') as $forbidden){
 openapiAssert(strpos($encoded,$forbidden)===false,'OpenAPI leaks internal field '.$forbidden);
}
foreach(array('VideoSummaryResponse','ErrorResponse','PlaybackResponse','TokenResponse','ProgressRequest') as $example){
 openapiAssert(isset($document['components']['examples'][$example]['value']),'Missing stable example '.$example);
}

$route=ApiV1Bootstrap::resolve(array('REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/api/v1/openapi.json','SCRIPT_NAME'=>'/index.php'));
openapiSame('/v1.docs/openapi',$route['path_info'],'OpenAPI route mismatch.');
$wrong=ApiV1Bootstrap::resolve(array('REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/api/v1/openapi.json','SCRIPT_NAME'=>'/index.php'));
openapiSame('/v1.index/methodNotAllowed',$wrong['path_info'],'OpenAPI route must reject POST.');

$controller=file_get_contents($root.'/application/api/controller/v1/Docs.php');
openapiAssert(strpos($controller,'cacheableRawDocumentResponse')!==false,'OpenAPI endpoint must return a raw conditional document.');
openapiAssert(strpos($controller,'ApiV1OpenApi::raw')!==false,'OpenAPI controller must use the checked-in contract.');

fwrite(STDOUT,"API v1 OpenAPI contract passed.".PHP_EOL);
