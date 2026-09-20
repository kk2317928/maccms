<?php
declare(strict_types=1);

function playbackFail($message){fwrite(STDERR,"FAIL: ".$message.PHP_EOL);exit(1);}
function playbackAssert($condition,$message){if(!$condition)playbackFail($message);}
function playbackSame($expected,$actual,$message){if($expected!==$actual)playbackFail($message." expected=".var_export($expected,true)." actual=".var_export($actual,true));}

$root=dirname(__DIR__,2);
foreach(array(
 'application/common/util/ApiV1PlaybackPolicy.php',
 'application/common/util/ApiV1PlaybackSigner.php',
 'application/common/util/ApiV1PlaybackDto.php',
 'application/common/util/ApiV1PlaybackService.php',
 'application/api/controller/v1/Playback.php',
) as $file) playbackAssert(is_file($root.'/'.$file),'Missing '.$file);

require_once $root.'/application/common/util/ApiV1Dto.php';
require_once $root.'/application/common/util/ExternalHttpPolicy.php';
require_once $root.'/application/common/util/ApiV1PlaybackPolicy.php';
require_once $root.'/application/common/util/ApiV1PlaybackSigner.php';
require_once $root.'/application/common/util/ApiV1PlaybackDto.php';
require_once $root.'/application/common/util/ApiV1Bootstrap.php';

use app\common\util\ApiV1PlaybackPolicy;
use app\common\util\ApiV1PlaybackSigner;
use app\common\util\ApiV1PlaybackDto;
use app\common\util\ApiV1Bootstrap;

$resolver=function($host){return array('cdn.example.com'=>'8.8.8.8','evil.example'=>'127.0.0.1')[$host]??'1.1.1.1';};
$policy=new ApiV1PlaybackPolicy(array(
 'source-a'=>array('enabled'=>true,'allowed_hosts'=>array('cdn.example.com')),
 'source-off'=>array('enabled'=>false,'allowed_hosts'=>array('cdn.example.com')),
),function($host)use($resolver){return array($resolver($host));});
$allowed=$policy->authorize('source-a','https://cdn.example.com/video/master.m3u8');
playbackSame('cdn.example.com',$allowed['host'],'Allowed playback host mismatch.');
foreach(array(
 array('source-off','https://cdn.example.com/v.m3u8'),
 array('unknown','https://cdn.example.com/v.m3u8'),
 array('source-a','http://cdn.example.com/v.m3u8'),
 array('source-a','https://evil.example/v.m3u8'),
 array('source-a','javascript:alert(1)'),
 array('source-a','https://user:pass@cdn.example.com/v.m3u8'),
) as $case){try{$policy->authorize($case[0],$case[1]);playbackFail('Unsafe playback URL accepted.');}catch(InvalidArgumentException $expected){}}

$signer=new ApiV1PlaybackSigner(str_repeat('s',32),300);
$signed=$signer->sign('ABC234','s1','s1e2',1700000000);
playbackSame(1700000300,$signed['expires_at'],'Playback expiry mismatch.');
playbackAssert($signer->verify('ABC234','s1','s1e2',$signed['expires_at'],$signed['signature'],1700000001),'Valid signature rejected.');
playbackAssert(!$signer->verify('ABC234','s1','s1e3',$signed['expires_at'],$signed['signature'],1700000001),'Signature must bind episode.');
playbackAssert(!$signer->verify('ABC234','s1','s1e2',$signed['expires_at'],$signed['signature'],1700000300),'Expired signature accepted.');

$dto=ApiV1PlaybackDto::make(array('public_id'=>'ABC234','source_id'=>'s1','episode_id'=>'s1e2','url'=>'https://cdn.example.com/v.m3u8','expires_at'=>1700000300))->toArray();
playbackSame(array('public_id','source_id','episode_id','url','expires_at'),array_keys($dto),'Playback DTO allowlist mismatch.');
playbackAssert(!array_key_exists('server',$dto)&&!array_key_exists('note',$dto),'Internal playback metadata leaked.');

$route=ApiV1Bootstrap::resolve(array('REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/api/v1/videos/ABC234/playback/s1/s1e2','SCRIPT_NAME'=>'/index.php'));
playbackSame('/v1.playback/resolve/public_id/ABC234/source_id/s1/episode_id/s1e2',$route['path_info'],'Playback route mismatch.');
$wrong=ApiV1Bootstrap::resolve(array('REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/api/v1/videos/ABC234/playback/s1/s1e2','SCRIPT_NAME'=>'/index.php'));
playbackSame('/v1.index/methodNotAllowed',$wrong['path_info'],'Playback route must reject POST.');

$service=file_get_contents($root.'/application/common/util/ApiV1PlaybackService.php');
playbackAssert(strpos($service,'VodPlaybackCodec::decode')!==false,'Playback must reuse native codec.');
playbackAssert(strpos($service,'ApiV1CatalogRepository::PUBLICATION_SQL')!==false,'Playback must enforce published visibility.');
playbackAssert(strpos($service,'vod_play_note')!==false,'Service must read native parallel columns.');
$controller=file_get_contents($root.'/application/api/controller/v1/Playback.php');
playbackAssert(strpos($controller,'server')===false && strpos($controller,'vod_id')===false,'Controller must not expose internal playback metadata.');

fwrite(STDOUT,"API v1 playback delivery contract passed.".PHP_EOL);
