<?php
declare(strict_types=1);

require_once __DIR__.'/../../application/common/util/ApiV1Dto.php';
require_once __DIR__.'/../../application/common/util/ApiV1Locale.php';
require_once __DIR__.'/../../application/common/util/ApiV1VideoDto.php';
require_once __DIR__.'/../../application/common/util/ApiV1Bootstrap.php';
require_once __DIR__.'/../../application/common/util/ApiV1PeopleService.php';
require_once __DIR__.'/../../application/common/util/ApiV1SiteConfig.php';

use app\common\util\ApiV1Locale;
use app\common\util\ApiV1Bootstrap;
use app\common\util\ApiV1PeopleService;
use app\common\util\ApiV1SiteConfig;

function peopleFail($message){fwrite(STDERR,"FAIL: ".$message.PHP_EOL);exit(1);}
function peopleAssert($condition,$message){if(!$condition)peopleFail($message);}

$person=['actor_id'=>123,'actor_name'=>'Jessica Jung','actor_alias'=>'鄭秀妍,郑秀妍','actor_pic'=>'/jessica.jpg','actor_content'=>'Biography','actor_status'=>1];
$slug=ApiV1PeopleService::slug(123);
peopleAssert(strlen($slug)===6 && preg_match('/^[A-Z0-9]{6}$/',$slug)===1,'person slug must be six public characters');
peopleAssert($slug===ApiV1PeopleService::slug(123) && $slug!==ApiV1PeopleService::slug(124),'person slug mapping must be stable and collision-free');
peopleAssert($slug!=='00003V' && strpos($slug,'123')===false,'person slug must not expose an incrementing ID');

$videos=[
 ['public_id'=>'PUB001','vod_name'=>'Published','vod_status'=>1,'workflow_status'=>'published','merged_into_vod_id'=>null,'vod_year'=>'2026','published_at'=>100],
 ['public_id'=>'DRAFT1','vod_name'=>'Draft','vod_status'=>0,'workflow_status'=>'manual_review','merged_into_vod_id'=>null],
 ['public_id'=>'MERGED','vod_name'=>'Merged','vod_status'=>1,'workflow_status'=>'published','merged_into_vod_id'=>9],
];
$requestedActorId=0;$requestedName='';
$service=new ApiV1PeopleService(function($actorId)use($person,&$requestedActorId){$requestedActorId=$actorId;return $actorId===123?$person:null;},function($name)use($videos,&$requestedName){$requestedName=$name;return $videos;});
$result=$service->detail($slug,ApiV1Locale::fromCode('en'));
peopleAssert($result['slug']===$slug && $result['name']==='Jessica Jung','person public identity mismatch');
peopleAssert($result['aliases']===['鄭秀妍','郑秀妍'],'person aliases must be explicit');
peopleAssert(count($result['videos'])===1 && $result['videos'][0]['public_id']==='PUB001','only published unmerged videos may be returned');
peopleAssert($requestedActorId===123 && $requestedName==='Jessica Jung','person and video queries must be scoped to the resolved identity');
peopleAssert($service->detail('ZZZZZZ',ApiV1Locale::fromCode('en'))===null,'unknown person must resolve to 404 boundary');
foreach(['actor_id','vod_id','workflow_status','merged_into_vod_id'] as $internal)peopleAssert(strpos(json_encode($result),$internal)===false,'person DTO leaks '.$internal);

$config=new ApiV1SiteConfig([
 'site'=>['site_name'=>'MACCMS','site_url'=>'https://video.example','site_description'=>'Video','site_logo'=>'/logo.png','site_waplogo'=>'/mobile.png','site_tj'=>'secret-js'],
 'app'=>['lang'=>'zh-tw','cache_password'=>'cache-secret','search'=>'1'],
 'email'=>['phpmailer'=>['password'=>'smtp-secret']],
 'upload'=>['api'=>['qiniu'=>['secretkey'=>'storage-secret']]],
]);
$public=$config->toArray();
peopleAssert(array_keys($public)===['name','base_url','description','logo','mobile_logo','language','search_enabled'],'site config public keys changed');
peopleAssert($public['name']==='MACCMS' && $public['search_enabled']===true,'site config values mismatch');
$encoded=json_encode($public);
foreach(['secret','password','site_tj','cache','email','upload'] as $forbidden)peopleAssert(stripos($encoded,$forbidden)===false,'site config leaks '.$forbidden);
$GLOBALS['config']=['site'=>['site_name'=>'Runtime Name'],'app'=>['lang'=>'en','search'=>'0']];
$runtime=(new ApiV1SiteConfig())->toArray();
peopleAssert($runtime['name']==='Runtime Name' && $runtime['language']==='en','site config must read the native runtime configuration');

$personRoute=ApiV1Bootstrap::resolve(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/api/v1/people/'.$slug,'SCRIPT_NAME'=>'/api.php']);
$configRoute=ApiV1Bootstrap::resolve(['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/api/v1/site-config','SCRIPT_NAME'=>'/api.php']);
$importRoute=ApiV1Bootstrap::resolve(['REQUEST_METHOD'=>'POST','REQUEST_URI'=>'/api/v1/import/videos','SCRIPT_NAME'=>'/api.php']);
peopleAssert($personRoute['path_info']==='/v1.people/detail/slug/'.$slug,'person route mismatch');
peopleAssert($configRoute['path_info']==='/v1.site_config/index','site config route mismatch');
peopleAssert($importRoute['path_info']==='/v1.import/videos','protected import route must remain reachable');

fwrite(STDOUT,"API v1 people and site config regression passed.".PHP_EOL);
