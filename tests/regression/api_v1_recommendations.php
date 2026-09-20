<?php
declare(strict_types=1);
function rec_ok($c,string $m):void{if(!$c){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
$root=dirname(__DIR__,2);
require_once $root.'/application/common/util/VideoRecommendationService.php';
use app\common\util\VideoRecommendationService;
$seed=['vod_id'=>10,'genres'=>['drama','crime'],'regions'=>['tw'],'tags'=>['mystery'],'people'=>['p1'],'published_at'=>1000];
$candidates=[
 ['vod_id'=>11,'public_id'=>'AAA222','genres'=>['drama','crime'],'regions'=>['tw'],'tags'=>['mystery'],'people'=>['p1'],'popularity'=>8,'published_at'=>1900,'available'=>true],
 ['vod_id'=>12,'public_id'=>'AAA223','genres'=>['drama'],'regions'=>['jp'],'tags'=>[],'people'=>[],'popularity'=>20,'published_at'=>1800,'available'=>true],
 ['vod_id'=>10,'public_id'=>'AAA224','genres'=>['drama'],'regions'=>['tw'],'tags'=>[],'people'=>[],'popularity'=>99,'published_at'=>2000,'available'=>true],
 ['vod_id'=>13,'public_id'=>'AAA225','genres'=>['drama','crime'],'regions'=>['tw'],'tags'=>['mystery'],'people'=>['p1'],'popularity'=>99,'published_at'=>2000,'available'=>false],
 ['vod_id'=>14,'public_id'=>'AAA226','genres'=>['drama'],'regions'=>['tw'],'tags'=>[],'people'=>[],'popularity'=>20,'published_at'=>1800,'available'=>true],
];
$rows=(new VideoRecommendationService())->recommend($seed,$candidates,3,2000);
rec_ok(array_column($rows,'public_id')===['AAA222','AAA226','AAA223'],'deterministic order and exclusions');
rec_ok($rows[0]['score']>$rows[1]['score'],'strong similarity wins');
rec_ok(in_array('shared_genre:crime',$rows[0]['reasons'],true),'explanation names shared feature');
rec_ok(in_array('shared_person:p1',$rows[0]['reasons'],true),'explanation names shared person');
rec_ok(count($rows)===3 && !in_array('AAA224',array_column($rows,'public_id'),true) && !in_array('AAA225',array_column($rows,'public_id'),true),'unavailable/current never leak');
fwrite(STDOUT,"Video recommendations passed.\n");
