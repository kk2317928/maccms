<?php

declare(strict_types=1);

$database=(string)getenv('MACCMS_TEST_DATABASE'); if(!preg_match('/^maccms_ci_[a-z0-9_]+$/',$database)){fwrite(STDERR,"FAIL: disposable database required\n");exit(1);}
$root=dirname(__DIR__,2); define('APP_PATH',$root.'/application/'); define('ENTRANCE','command'); $_SERVER['HTTP_HOST']='127.0.0.1'; $_SERVER['SCRIPT_NAME']='/taxonomy-review'; require $root.'/thinkphp/base.php'; \think\App::initCommon();

$vodId=991001; \think\Db::name('vod_meta_term')->where('vod_id',$vodId)->delete(); \think\Db::name('content_taxonomy_suggestion')->where('vod_id',$vodId)->delete(); \think\Db::name('vod_field_state')->where('vod_id',$vodId)->delete();
$termId=(int)\think\Db::name('meta_term')->insertGetId(['kind'=>'region','slug'=>'taiwan-ci','name_tw'=>'台灣 CI','name_cn'=>'台湾 CI','name_en'=>'Taiwan CI','synonyms_json'=>'["TW-CI"]','status'=>1,'sort'=>1,'created_at'=>time(),'updated_at'=>time()]);
$service=new \app\common\util\TaxonomySuggestionService(static fn():int=>600);
$rows=$service->stage($vodId,'ai','ai_run:991',['regions'=>['TW-CI']]);
if(count($rows)!==1 || (int)$rows[0]['matched_term_id']!==$termId){fwrite(STDERR,"FAIL: unique active term was not staged\n");exit(1);}
$service->review((int)$rows[0]['suggestion_id'],'accept',1,'ci-admin');
if(\think\Db::name('vod_meta_term')->where(['vod_id'=>$vodId,'term_id'=>$termId])->count()!==1){fwrite(STDERR,"FAIL: reviewed term relation missing\n");exit(1);}
$again=$service->stage($vodId,'ai','ai_run:991',['regions'=>['TW-CI']]); if((int)$again[0]['suggestion_id']!==(int)$rows[0]['suggestion_id']){fwrite(STDERR,"FAIL: staging is not idempotent\n");exit(1);}
fwrite(STDOUT,"OK: reviewed taxonomy relation passed on {$database}\n");
