<?php
declare(strict_types=1);

function deliveryFail($message){fwrite(STDERR,"FAIL: ".$message.PHP_EOL);exit(1);}
function deliveryAssert($condition,$message){if(!$condition)deliveryFail($message);}
function deliverySame($expected,$actual,$message){if($expected!==$actual)deliveryFail($message." expected=".var_export($expected,true)." actual=".var_export($actual,true));}

$root=dirname(__DIR__,2);
foreach(array(
 'application/common/util/ApiV1EndpointPolicy.php',
 'application/common/util/ApiV1CorsPolicy.php',
 'application/common/util/ApiV1RateLimiter.php',
 'application/common/util/ApiV1Etag.php',
) as $file) deliveryAssert(is_file($root.'/'.$file),'Missing '.$file);

require_once $root.'/application/common/util/ApiV1EndpointPolicy.php';
require_once $root.'/application/common/util/ApiV1CorsPolicy.php';
require_once $root.'/application/common/util/ApiV1RateLimiter.php';
require_once $root.'/application/common/util/ApiV1Etag.php';

use app\common\util\ApiV1EndpointPolicy;
use app\common\util\ApiV1CorsPolicy;
use app\common\util\ApiV1RateLimiter;
use app\common\util\ApiV1Etag;

$catalog=ApiV1EndpointPolicy::resolve('GET','/api/v1/videos');
deliverySame('catalog',$catalog['bucket'],'Catalog bucket mismatch.');
deliverySame(120,$catalog['limit'],'Catalog limit mismatch.');
deliveryAssert($catalog['cacheable']===true,'Catalog GET must be cacheable.');
$search=ApiV1EndpointPolicy::resolve('GET','/api/v1/search');
deliverySame(30,$search['limit'],'Search limit mismatch.');
$auth=ApiV1EndpointPolicy::resolve('POST','/api/v1/auth/login');
deliverySame('auth',$auth['bucket'],'Auth bucket mismatch.');
deliverySame(10,$auth['limit'],'Auth limit mismatch.');
deliveryAssert($auth['cacheable']===false,'Auth must not be cacheable.');
$playback=ApiV1EndpointPolicy::resolve('GET','/api/v1/videos/ABC234/playback/s1/s1e1');
deliverySame('playback',$playback['bucket'],'Playback bucket mismatch.');
deliverySame(30,$playback['limit'],'Playback limit mismatch.');
deliveryAssert($playback['cacheable']===false,'Playback must not be cacheable.');

$cors=new ApiV1CorsPolicy(array('https://app.example.com'));
$headers=$cors->headers('https://app.example.com','GET',$catalog['methods']);
deliverySame('https://app.example.com',$headers['Access-Control-Allow-Origin'],'CORS origin mismatch.');
deliverySame('Origin',$headers['Vary'],'CORS must vary by Origin.');
deliveryAssert(strpos($headers['Access-Control-Expose-Headers'],'ETag')!==false,'CORS must expose ETag.');
foreach(array('https://evil.example','http://app.example.com','*','https://user:pass@app.example.com') as $origin){
 try{$cors->headers($origin,'GET',$catalog['methods']);deliveryFail('Unsafe CORS origin accepted: '.$origin);}
 catch(InvalidArgumentException $expected){}
}
$preflight=$cors->preflight('https://app.example.com','GET',$catalog['methods']);
deliverySame('GET, OPTIONS',$preflight['Access-Control-Allow-Methods'],'Preflight methods mismatch.');
deliveryAssert(strpos($preflight['Access-Control-Allow-Headers'],'Authorization')!==false,'Authorization header must be allowed.');

$dir=sys_get_temp_dir().'/maccms-api-v1-rate-'.bin2hex(random_bytes(6));
$limiter=new ApiV1RateLimiter($dir);
$r1=$limiter->consume('auth','203.0.113.10',2,60,1700000000);
$r2=$limiter->consume('auth','203.0.113.10',2,60,1700000001);
$r3=$limiter->consume('auth','203.0.113.10',2,60,1700000002);
deliveryAssert($r1['allowed']&&$r2['allowed'],'Allowed rate-limit hits rejected.');
deliveryAssert(!$r3['allowed']&&$r3['retry_after']===58,'Rate limit did not reject deterministically.');
$r4=$limiter->consume('catalog','203.0.113.10',2,60,1700000002);
deliveryAssert($r4['allowed'],'Endpoint buckets must be independent.');
$r5=$limiter->consume('auth','203.0.113.10',2,60,1700000060);
deliveryAssert($r5['allowed'],'Expired rate-limit window did not reset.');

$payload=array('data'=>array('public_id'=>'ABC234','title'=>'Example'),'meta'=>array('locale'=>'zh-TW'));
$etag=ApiV1Etag::make($payload,7);
deliveryAssert(preg_match('/\\A"[a-f0-9]{64}"\\z/D',$etag)===1,'ETag format mismatch.');
deliveryAssert(ApiV1Etag::matches($etag,$etag),'Exact If-None-Match rejected.');
deliveryAssert(ApiV1Etag::matches('W/'.$etag.', "other"',$etag),'Weak/list If-None-Match rejected.');
deliveryAssert(!ApiV1Etag::matches('"other"',$etag),'Different ETag accepted.');
deliveryAssert(ApiV1Etag::make($payload,8)!==$etag,'Publication generation must invalidate ETag.');
deliveryAssert(ApiV1Etag::make(array('data'=>array('public_id'=>'ABC234','title'=>'Changed')),7)!==$etag,'Content change must invalidate ETag.');

foreach(glob($dir.'/*')?:array() as $file) @unlink($file);
@rmdir($dir);
$behaviorPath=$root.'/application/common/behavior/ApiV1DeliveryPolicy.php';
deliveryAssert(is_file($behaviorPath),'Missing API v1 delivery behavior.');
$behavior=file_get_contents($behaviorPath);
deliveryAssert(strpos($behavior,'REMOTE_ADDR')!==false && strpos($behavior,'HTTP_X_FORWARDED_FOR')===false,'Rate limit identity must default to direct peer IP.');
deliveryAssert(strpos($behavior,'ApiV1RateLimiter')!==false,'Delivery behavior must enforce rate limits.');
deliveryAssert(strpos($behavior,'ApiV1CorsPolicy')!==false,'Delivery behavior must enforce CORS.');
deliveryAssert(strpos($behavior,'Retry-After')!==false && strpos($behavior,'429')!==false,'Rate-limit response contract missing.');
deliveryAssert(strpos($behavior,'204')!==false,'CORS preflight must terminate with 204.');
$tags=file_get_contents($root.'/application/tags.php');
deliveryAssert(strpos($tags,'ApiV1DeliveryPolicy')!==false,'Delivery policy behavior is not registered.');

$base=file_get_contents($root.'/application/api/controller/v1/Base.php');
deliveryAssert(strpos($base,'ApiV1Etag')!==false,'API base must generate ETags.');
deliveryAssert(strpos($base,'If-None-Match')!==false && strpos($base,'304')!==false,'Conditional GET handling missing.');
deliveryAssert(strpos($base,"'Cache-Control'=>'public, max-age=60, stale-while-revalidate=300'")!==false,'Public cache policy mismatch.');
$catalogController=file_get_contents($root.'/application/api/controller/v1/Catalog.php');
deliveryAssert(strpos($catalogController,'cacheableResponse')!==false,'Catalog detail responses must use validators.');
deliveryAssert(strpos($catalogController,'cacheableCollectionResponse')!==false,'Catalog collections must use validators.');
$authController=file_get_contents($root.'/application/api/controller/v1/Auth.php');
$playbackController=file_get_contents($root.'/application/api/controller/v1/Playback.php');
deliveryAssert(strpos($authController,"'Cache-Control'=>'no-store'")!==false,'Token responses must remain no-store.');
deliveryAssert(strpos($playbackController,"'Cache-Control'=>'private, no-store'")!==false,'Playback responses must remain private no-store.');

fwrite(STDOUT,"API v1 delivery policy contract passed.".PHP_EOL);
