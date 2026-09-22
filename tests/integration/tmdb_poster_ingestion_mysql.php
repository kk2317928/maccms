<?php

declare(strict_types=1);

$database=(string)getenv('MACCMS_TEST_DATABASE');
if(!preg_match('/^maccms_ci_[a-z0-9_]+$/',$database)){fwrite(STDERR,"FAIL: disposable database required\n");exit(1);}
$root=dirname(__DIR__,2);
define('APP_PATH',$root.'/application/'); define('ENTRANCE','command');
$_SERVER['HTTP_HOST']='127.0.0.1'; $_SERVER['SCRIPT_NAME']='/tmdb-poster';
require $root.'/thinkphp/base.php'; \think\App::initCommon();

use app\common\util\ExternalImageIngestionService;
use app\common\util\TmdbPosterJobHandler;
use app\common\util\FieldGovernance;

function posterMysqlAssert($ok,string $msg):void{if(!$ok){fwrite(STDERR,"FAIL: {$msg}\n");exit(1);}}

$vodId=(int)\think\Db::name('vod')->insertGetId(['type_id'=>0,'vod_name'=>'Poster fixture','vod_pic'=>'https://old.example/poster.jpg','vod_status'=>3,'vod_content'=>'','vod_blurb'=>'','vod_time'=>time(),'vod_time_add'=>time()]);
\app\common\util\VodExtensionService::ensure($vodId);
\think\Db::name('vod_ext')->where('vod_id',$vodId)->update(['old_poster_s3'=>'https://older.example/poster.jpg','poster_s3'=>'https://old-s3.example/poster.jpg']);

$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
$http=new class($png) extends \app\common\util\HardenedHttpClient {private $b;public function __construct($b){$this->b=$b;}public function get(string $url,array $o=[]):array{return ['status'=>200,'headers'=>[],'body'=>$this->b];}};
$tmp=sys_get_temp_dir().'/poster-mysql-'.bin2hex(random_bytes(4));@mkdir($tmp,0755,true);
$ingest=new ExternalImageIngestionService($http,fn($p)=>'https://cdn.example/'.$p,$tmp);
$handler=new TmdbPosterJobHandler($ingest,new FieldGovernance());
$handler->handle(['vod_id'=>$vodId,'poster_url'=>'https://image.tmdb.org/a.png','source_ref'=>'tmdb:movie:99:admin:1']);
$vod=\think\Db::name('vod')->where('vod_id',$vodId)->find();$ext=\think\Db::name('vod_ext')->where('vod_id',$vodId)->find();
posterMysqlAssert(strpos((string)$vod['vod_pic'],'https://cdn.example/')===0,'vod_pic not updated to stored poster.');
posterMysqlAssert((string)$ext['old_poster_s3']==='https://old-s3.example/poster.jpg','old_poster_s3 did not preserve previous stored poster.');
posterMysqlAssert((string)$ext['poster_s3']===(string)$vod['vod_pic'],'poster_s3 and vod_pic diverged.');

$before=[(string)$vod['vod_pic'],(string)$ext['old_poster_s3'],(string)$ext['poster_s3']];
$bad=new class extends \app\common\util\HardenedHttpClient {public function __construct(){}public function get(string $u,array $o=[]):array{return ['status'=>200,'headers'=>[],'body'=>'broken'];}};
try{(new TmdbPosterJobHandler(new ExternalImageIngestionService($bad,fn($p)=>'https://cdn/'.$p,$tmp),new FieldGovernance()))->handle(['vod_id'=>$vodId,'poster_url'=>'https://image.tmdb.org/b.jpg','source_ref'=>'tmdb:movie:100:admin:1']);posterMysqlAssert(false,'invalid image unexpectedly succeeded');}catch(Throwable $e){}
$vod2=\think\Db::name('vod')->where('vod_id',$vodId)->find();$ext2=\think\Db::name('vod_ext')->where('vod_id',$vodId)->find();
posterMysqlAssert($before===[(string)$vod2['vod_pic'],(string)$ext2['old_poster_s3'],(string)$ext2['poster_s3']],'failed ingestion changed poster fields.');

fwrite(STDOUT,"PASS: TMDB poster MySQL round trip and failure atomicity.\n");
