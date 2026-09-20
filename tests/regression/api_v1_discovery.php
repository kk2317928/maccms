<?php
declare(strict_types=1);
function api_ok($c,string $m):void{if(!$c){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
$root=dirname(__DIR__,2);
require_once $root.'/application/common/util/ApiV1Bootstrap.php';
use app\common\util\ApiV1Bootstrap;
$routes=[
 ['POST','/api/v1/events','/v1.events/ingest'],
 ['GET','/api/v1/rankings','/v1.discovery/rankings'],
 ['GET','/api/v1/videos/ABC234/recommendations','/v1.discovery/recommendations/public_id/ABC234'],
];
foreach($routes as [$method,$path,$target]){
 $r=ApiV1Bootstrap::resolve(['REQUEST_METHOD'=>$method,'PATH_INFO'=>$path]);
 api_ok($r['path_info']===$target,"route {$method} {$path}");
}
api_ok(ApiV1Bootstrap::resolve(['REQUEST_METHOD'=>'GET','PATH_INFO'=>'/api/v1/events'])['path_info']==='/v1.index/methodNotAllowed','events POST only');
foreach([
 'application/api/controller/v1/Events.php'=>['class Events','ApiV1EventContract','VideoEventPolicy'],
 'application/api/controller/v1/Discovery.php'=>['class Discovery','rankings','recommendations'],
 'application/common/util/ApiV1EventRepository.php'=>['ON DUPLICATE KEY UPDATE','dedupe_hash','ApiV1CatalogRepository::PUBLICATION_SQL'],
 'application/common/util/ApiV1DiscoveryRepository.php'=>['ApiV1CatalogRepository::PUBLICATION_SQL','api_video_rank','VideoRecommendationService'],
] as $file=>$needles){
 $s=file_get_contents($root.'/'.$file);api_ok($s!==false,"{$file} exists");
 foreach($needles as $n)api_ok(strpos($s,$n)!==false,"{$file} contains {$n}");
}
fwrite(STDOUT,"CP-08 API boundaries passed.\n");
