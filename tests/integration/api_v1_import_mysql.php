<?php
declare(strict_types=1);
$database=(string)getenv('MACCMS_TEST_DATABASE');if(!preg_match('/^maccms_ci_[a-z0-9_]+$/',$database)){fwrite(STDERR,"FAIL: disposable database required\n");exit(1);}
$root=dirname(__DIR__,2);define('APP_PATH',$root.'/application/');define('ENTRANCE','command');$_SERVER['HTTP_HOST']='127.0.0.1';$_SERVER['SCRIPT_NAME']='/import-ledger';require $root.'/thinkphp/base.php';\think\App::initCommon();
\think\Db::name('video_import_nonce')->where('nonce','ci-nonce-123456')->delete();\think\Db::name('video_import_request')->where('idempotency_key','ci-request-000001')->delete();
if(\think\Db::name('video_import_nonce')->insert(['nonce'=>'ci-nonce-123456','request_timestamp'=>time(),'created_at'=>time()])!==1){fwrite(STDERR,"FAIL: nonce claim failed\n");exit(1);}
try{\think\Db::name('video_import_nonce')->insert(['nonce'=>'ci-nonce-123456','request_timestamp'=>time(),'created_at'=>time()]);fwrite(STDERR,"FAIL: nonce replay accepted\n");exit(1);}catch(\Throwable $e){}
$writes=0;$service=new \app\common\util\ContentImportService(null,null,null,function($payload)use(&$writes){$writes++;return ['code'=>1,'vod_id'=>991108];},function($id){return ['vod_id'=>$id,'public_id'=>'CI1108','workflow_status'=>'imported'];});
$payload=['vod_name'=>'Import CI','type_id'=>1,'vod_play_from'=>['hls'],'vod_play_url'=>['EP$https://cdn.example.test/ci.m3u8']];
$a=$service->import($payload,'ci-request-000001');$b=$service->import($payload,'ci-request-000001');
if($a!==$b || $writes!==1 || $a['public_id']!=='CI1108'){fwrite(STDERR,"FAIL: database idempotency contract failed\n");exit(1);}
fwrite(STDOUT,"OK: protected import ledger passed on {$database}\n");
