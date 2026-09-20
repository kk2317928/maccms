<?php
declare(strict_types=1);

require_once __DIR__ . '/../../application/common/util/ContentImportAuthenticator.php';
require_once __DIR__ . '/../../application/common/util/ContentImportService.php';

use app\common\util\ContentImportAuthenticator;
use app\common\util\ContentImportService;

function importFail($message){fwrite(STDERR,"FAIL: ".$message.PHP_EOL);exit(1);}
function importAssert($condition,$message){if(!$condition)importFail($message);}
function importThrows(callable $fn,$message){try{$fn();}catch(\InvalidArgumentException $e){return;}importFail($message);}

$secret=str_repeat('s',32);$now=1700000000;$claims=[];
$auth=new ContentImportAuthenticator($secret,function()use($now){return $now;},function($nonce,$timestamp)use(&$claims){if(isset($claims[$nonce]))return false;$claims[$nonce]=$timestamp;return true;});
$body='{"vod_name":"Arrival","type_id":1}';
$signature=hash_hmac('sha256',$now."\nnonce-1234567890\nPOST\n/api/v1/import/videos\n".hash('sha256',$body),$secret);
importAssert($auth->authenticate('POST','/api/v1/import/videos',$body,(string)$now,'nonce-1234567890',$signature)===true,'valid independent HMAC credential must authenticate');
importThrows(function()use($auth,$body,$now,$signature){$auth->authenticate('POST','/api/v1/import/videos',$body,(string)$now,'nonce-1234567890',$signature);},'nonce replay must be rejected');
importThrows(function()use($auth,$body,$now){$auth->authenticate('POST','/api/v1/import/videos',$body,(string)($now-301),'nonce-abcdefghijk','00');},'stale timestamp must be rejected');

$ledger=[];$writes=0;$jobs=0;
$service=new ContentImportService(
    function($key)use(&$ledger){return $ledger[$key]??null;},
    function($key,$fingerprint)use(&$ledger){$ledger[$key]=['request_fingerprint'=>$fingerprint,'status'=>'processing','response_json'=>null];},
    function($key,$result)use(&$ledger){$ledger[$key]['status']='succeeded';$ledger[$key]['response_json']=json_encode($result);},
    function($payload)use(&$writes,&$jobs){$writes++;$jobs++;importAssert(!isset($payload['vod_status']),'import must not publish');return ['code'=>1,'vod_id'=>42];},
    function($vodId){return ['vod_id'=>$vodId,'public_id'=>'ABC123','workflow_status'=>'imported'];},
    function($callback){return $callback();}
);
$payload=['vod_name'=>'Arrival','type_id'=>1,'vod_play_from'=>['hls'],'vod_play_url'=>['正片$https://cdn.example.test/a.m3u8']];
$first=$service->import($payload,'request-00000001');$second=$service->import($payload,'request-00000001');
importAssert($first===$second,'idempotent replay must return identical result');
importAssert($writes===1 && $jobs===1,'idempotent replay must perform one native write and one native AI enqueue');
importAssert($first['public_id']==='ABC123' && $first['workflow_status']==='imported','native extension result must be returned');
importThrows(function()use($service){$service->import(['vod_name'=>'Bad','type_id'=>1,'vod_status'=>1],'request-00000002');},'publishing field must be rejected');
importThrows(function()use($service){$service->import(['vod_name'=>'Bad','type_id'=>1,'merged_into_vod_id'=>9],'request-00000003');},'merge field must be rejected');
importThrows(function()use($service){$service->import(['vod_name'=>'Bad','type_id'=>1,'vod_play_url'=>['x$javascript:alert(1)']],'request-00000004');},'unsafe playback URL must be rejected');
importThrows(function()use($service,$payload){$changed=$payload;$changed['vod_year']='2025';$service->import($changed,'request-00000001');},'idempotency key reuse with another payload must be rejected');
importThrows(function()use($service){$service->import(['vod_name'=>str_repeat('x',270000),'type_id'=>1],'request-00000005');},'oversized payload must be rejected');

fwrite(STDOUT,"API v1 import regression passed.".PHP_EOL);
