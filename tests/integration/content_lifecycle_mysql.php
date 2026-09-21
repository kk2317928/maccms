<?php

declare(strict_types=1);

$database=(string)getenv('MACCMS_TEST_DATABASE');
if(!preg_match('/^maccms_ci_[a-z0-9_]+$/',$database)){fwrite(STDERR,"FAIL: disposable database required\n");exit(1);}
$root=dirname(__DIR__,2);
define('APP_PATH',$root.'/application/');
define('ENTRANCE','command');
$_SERVER['HTTP_HOST']='127.0.0.1';
$_SERVER['HTTP_USER_AGENT']='maccms-lifecycle-ci';
$_SERVER['SCRIPT_NAME']='/content-lifecycle';
require $root.'/thinkphp/base.php';
\think\App::initCommon();
\think\Request::instance()->module('admin');

function lifecycle_assert($condition,string $message):void{if(!$condition){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}}

$run=static function() use($database):void{
$now=time();
\think\Db::execute('DELETE FROM `'.config('database.prefix').'content_job_run`');
\think\Db::execute('DELETE FROM `'.config('database.prefix').'content_job`');
$type=\think\Db::name('type')->where('type_id',1)->find();
if(!$type){
    \think\Db::name('type')->insert(['type_id'=>1,'type_name'=>'Lifecycle CI','type_en'=>'lifecycle-ci','type_sort'=>1,'type_mid'=>1,'type_pid'=>0,'type_status'=>1,'type_extend'=>'{}']);
    $type=\think\Db::name('type')->where('type_id',1)->find();
}
$maccms=config('maccms');
$maccms['app']['vod_search_optimise']='';
config('maccms',$maccms);
config('vodplayer',['dplayer'=>['status'=>'1']]);
$GLOBALS['config']=$maccms;
\think\Cache::set($maccms['app']['cache_flag'].'_type_list',[1=>$type]);

$title='Lifecycle CI '.substr(hash('sha256',$database),0,8);
$native=model('Vod')->saveData([
    'vod_id'=>0,'type_id'=>1,'vod_name'=>$title,'vod_en'=>'lifecycle-ci','vod_letter'=>'L','vod_status'=>0,
    'vod_year'=>'2026','vod_content'=>'Lifecycle integration fixture','vod_blurb'=>'Lifecycle summary',
    'vod_play_from'=>['dplayer'],'vod_play_url'=>['Episode 1$https://media.example.com/lifecycle.m3u8'],
    'vod_play_server'=>[''],'vod_play_note'=>[''],'vod_down_from'=>[],'vod_down_url'=>[],
    'vod_down_server'=>[],'vod_down_note'=>[],'vod_pic_screenshot'=>'','uptime'=>0,'uptag'=>0,
]);
lifecycle_assert((int)($native['code']??0)===1,'native video creation failed');
$vodId=(int)$native['vod_id'];
$ext=\think\Db::name('vod_ext')->where('vod_id',$vodId)->find();
lifecycle_assert($ext&&$ext['workflow_status']==='ai_processing','native creation did not enter AI processing');
$jobs=\think\Db::name('content_job')->where('job_type','ai_normalize')->where('idempotency_key','like','video:'.$vodId.':ai:%')->select();
lifecycle_assert(count($jobs)===1,'native creation must enqueue one AI job');
(new \app\common\util\ContentWorkflowCoordinator())->beginAi($vodId,(string)json_decode($jobs[0]['payload_json'],true)['content_fingerprint']);
lifecycle_assert(\think\Db::name('content_job')->where('job_type','ai_normalize')->where('idempotency_key','like','video:'.$vodId.':ai:%')->count()===1,'repeated AI start duplicated work');

$duplicateId=(int)\think\Db::name('vod')->insertGetId(['type_id'=>1,'vod_name'=>$title,'vod_en'=>'lifecycle-duplicate','vod_year'=>'2026','vod_status'=>0,'vod_recycle_time'=>0,'vod_content'=>'','vod_play_url'=>'','vod_down_url'=>'','vod_plot_name'=>'','vod_plot_detail'=>'']);
\think\Db::name('vod_ext')->insert(['vod_id'=>$duplicateId,'public_id'=>'LCD234','title_tw'=>$title,'title_cn'=>$title,'title_en'=>'Lifecycle CI','original_title'=>$title,'type2'=>'movie','workflow_status'=>'duplicate_review','merged_into_vod_id'=>0,'created_at'=>$now,'updated_at'=>$now]);
$regionId=(int)\think\Db::name('meta_term')->insertGetId(['kind'=>'region','slug'=>'lifecycle-region-'.$vodId,'name_tw'=>'生命週期地區','name_cn'=>'生命周期地区','name_en'=>'Lifecycle Region','status'=>1,'sort'=>1,'created_at'=>$now,'updated_at'=>$now]);
$genreId=(int)\think\Db::name('meta_term')->insertGetId(['kind'=>'genre','slug'=>'lifecycle-genre-'.$vodId,'name_tw'=>'生命週期分類','name_cn'=>'生命周期分类','name_en'=>'Lifecycle Genre','status'=>1,'sort'=>1,'created_at'=>$now,'updated_at'=>$now]);

$normalized=[
    'normalized_title'=>$title,'original_title'=>$title,'title_tw'=>$title,'title_cn'=>$title,'title_en'=>'Lifecycle CI',
    'aliases'=>[],'year'=>2026,'media_type'=>'movie','tmdb_clues'=>['title'=>$title,'year'=>2026,'type'=>'movie'],
    'taxonomy'=>['regions'=>['生命週期地區'],'genres'=>['生命週期分類'],'tags'=>[]],
    'confidence'=>1.0,'reason'=>'Deterministic lifecycle fixture',
];
$raw=json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$pipeline=new \app\common\util\AiNormalizationPipeline(
    static fn(array $request):array=>['raw_response'=>$raw,'input_tokens'=>10,'output_tokens'=>20,'estimated_cost_micros'=>3],
    new \app\common\util\AiNormalizationValidator(),
    new \app\common\util\AiRunRepository(static fn():int=>$now),
    new \app\common\util\FieldGovernance(static fn():int=>$now),
    ['provider'=>'fixture','model'=>'fixture-v1','prompt_version'=>'lifecycle-v1','daily_budget_micros'=>1000,'auto_adopt_empty'=>false],
    static fn():int=>$now,
    new \app\common\util\DuplicateCandidateDetector(null,null,1,static fn():int=>$now,20,0),
    new \app\common\util\TaxonomySuggestionService(static fn():int=>$now),
    new \app\common\util\ContentWorkflowCoordinator(null,null,null,null,static fn():int=>$now)
);
$fixtureTmdb=new \app\common\util\TmdbReviewJobHandler(
    new \app\common\util\TmdbReviewWorkspace(static fn():int=>$now),
    null,
    static fn(array $video):array=>['status'=>'candidate_review','candidates'=>[['id'=>900001,'media_type'=>'movie','title'=>'Lifecycle CI']],'preselected_id'=>900001],
    static fn(string $type,int $id):array=>['status'=>'candidate_review','candidates'=>[['id'=>$id,'media_type'=>$type,'title'=>'Lifecycle CI']],'preselected_id'=>$id]
);
$worker=new \app\common\util\ContentJobWorker(
    new \app\common\util\ContentJobRepository(static fn():int=>$now),
    \app\command\MaccmsJobs::handlerMap(new \app\common\util\AiNormalizationJobHandler($pipeline),$fixtureTmdb),
    static fn():int=>$now
);
$workerResult=$worker->run('lifecycle-ai',1,10,120);
$aiJobAfter=(new \app\common\util\ContentJobRepository())->find((int)$jobs[0]['job_id']);
lifecycle_assert($workerResult['succeeded']===1&&$workerResult['failed']===0,'production worker did not dispatch the AI job: '.json_encode(['result'=>$workerResult,'job'=>$aiJobAfter],JSON_UNESCAPED_SLASHES));
$runId=(int)\think\Db::name('content_ai_run')->where('vod_id',$vodId)->order('ai_run_id desc')->value('ai_run_id');
lifecycle_assert($runId>0,'AI run was not persisted through the worker');
lifecycle_assert(\think\Db::name('content_duplicate_candidate')->where(function($q)use($vodId){$q->where('vod_id_low',$vodId)->whereOr('vod_id_high',$vodId);})->count()===1,'AI completion did not create the duplicate branch');
lifecycle_assert(\think\Db::name('vod_ext')->where('vod_id',$vodId)->value('workflow_status')==='duplicate_review','AI completion did not enter duplicate review');

$taxonomy=new \app\common\util\TaxonomySuggestionService(static fn():int=>$now);
$suggestions=\think\Db::name('content_taxonomy_suggestion')->where(['vod_id'=>$vodId,'source_ref'=>'ai_run:'.$runId])->select();
lifecycle_assert(count($suggestions)===2,'AI taxonomy suggestions were not staged');
foreach($suggestions as $suggestion){$taxonomy->review((int)$suggestion['suggestion_id'],'accept',101,'lifecycle-admin');}
lifecycle_assert(\think\Db::name('vod_meta_term')->where('vod_id',$vodId)->count()===2,'reviewed taxonomy relations were not adopted');

$reviewService=new \app\common\util\AiFieldReviewService(new \app\common\util\FieldGovernance(static fn():int=>$now),new \app\common\util\ContentAdminAudit(),static fn():int=>$now,$taxonomy);
foreach(['vod_name','original_title','title_tw','title_cn','title_en','vod_year','type2'] as $field){$reviewService->review($runId,$field,'accept',null,101,'lifecycle-admin');}
lifecycle_assert(\think\Db::name('content_ai_run')->where('ai_run_id',$runId)->value('decision_status')==='reviewed','AI field review did not close all decisions');

$candidate=\think\Db::name('content_duplicate_candidate')->where(function($q)use($vodId){$q->where('vod_id_low',$vodId)->whereOr('vod_id_high',$vodId);})->where('decision','pending')->find();
lifecycle_assert((bool)$candidate,'duplicate candidate was not persisted');
$decision=new \app\common\util\DuplicateCandidateDecisionService(static fn():int=>$now,new \app\common\util\ContentWorkflowCoordinator(null,null,null,null,static fn():int=>$now));
lifecycle_assert($decision->markDifferent((int)$candidate['duplicate_candidate_id'],101),'different-work decision failed');
lifecycle_assert(\think\Db::name('vod_ext')->where('vod_id',$vodId)->value('workflow_status')==='tmdb_matching','duplicate decision did not enter TMDB matching');
lifecycle_assert(\think\Db::name('content_job')->where(['job_type'=>'tmdb_review','idempotency_key'=>'video:'.$vodId.':tmdb'])->count()===1,'TMDB work was not enqueued exactly once');

$tmdb=new \app\common\util\TmdbReviewWorkspace(static fn():int=>$now,new \app\common\util\ContentWorkflowCoordinator(null,null,null,null,static fn():int=>$now));
$tmdbHandler=new \app\common\util\TmdbReviewJobHandler(
    $tmdb,null,
    static fn(array $video):array=>['status'=>'candidate_review','candidates'=>[['id'=>900001,'media_type'=>'movie','title'=>'Lifecycle CI']],'preselected_id'=>900001],
    static fn(string $type,int $id):array=>['status'=>'candidate_review','candidates'=>[['id'=>$id,'media_type'=>$type,'title'=>'Lifecycle CI']],'preselected_id'=>$id]
);
$tmdbWorker=new \app\common\util\ContentJobWorker(
    new \app\common\util\ContentJobRepository(static fn():int=>$now),
    \app\command\MaccmsJobs::handlerMap(new \app\common\util\AiNormalizationJobHandler($pipeline),$tmdbHandler),
    static fn():int=>$now
);
$tmdbResult=$tmdbWorker->run('lifecycle-tmdb',1,10,120);
$tmdbJobsAfter=\think\Db::name('content_job')->where('job_type','tmdb_review')->order('job_id asc')->select();
lifecycle_assert($tmdbResult['succeeded']===1&&$tmdbResult['failed']===0,'production worker did not dispatch the TMDB job: '.json_encode(['result'=>$tmdbResult,'jobs'=>$tmdbJobsAfter],JSON_UNESCAPED_SLASHES));
$reviewId=(int)\think\Db::name('content_tmdb_review')->where('vod_id',$vodId)->order('tmdb_review_id desc')->value('tmdb_review_id');
lifecycle_assert($reviewId>0,'TMDB worker did not persist a review');
$tmdb->select($reviewId,'movie',900001,101);
lifecycle_assert(\think\Db::name('vod_ext')->where('vod_id',$vodId)->value('workflow_status')==='manual_review','TMDB review did not enter manual review');

$workspace=new \app\common\util\FinalPublicationWorkspace(null,null,null,null,null,['media.example.com']);
$preview=$workspace->preview($vodId);
lifecycle_assert($preview['publishable']===true,'manual-review video is not publishable: '.implode(';',$preview['blockers']));
$publisher=new \app\common\util\FinalPublicationService($workspace,null,null,null,null,null,static fn():int=>$now+10,static function():void{},static function():void{});
$published=$publisher->publish($vodId,101,'lifecycle-admin',['content_workspace/publish'],true,$preview['revision']);
lifecycle_assert($published['workflow_status']==='published','publication did not complete');
$detail=(new \app\common\util\ApiV1CatalogService())->detail((string)$published['public_id'],\app\common\util\ApiV1Locale::fromCode('zh-TW'));
lifecycle_assert($detail!==null&&$detail->toArray()['public_id']===$published['public_id'],'published video is not readable from API v1');

$jobCount=(int)\think\Db::name('content_job')->where('idempotency_key','like','video:'.$vodId.':%')->count();
$relationCount=(int)\think\Db::name('vod_meta_term')->where('vod_id',$vodId)->count();
$jobPayload=json_decode($jobs[0]['payload_json'],true);
(new \app\common\util\ContentJobRepository(static fn():int=>$now))->enqueue('ai_normalize',$jobPayload,(string)$jobs[0]['idempotency_key']);
$taxonomy->stage($vodId,'ai','ai_run:'.$runId,$normalized['taxonomy']);
lifecycle_assert((int)\think\Db::name('content_job')->where('idempotency_key','like','video:'.$vodId.':%')->count()===$jobCount,'lifecycle replay duplicated jobs');
lifecycle_assert((int)\think\Db::name('vod_meta_term')->where('vod_id',$vodId)->count()===$relationCount,'lifecycle replay duplicated taxonomy relations');

$primaryId=(int)\think\Db::name('vod')->insertGetId(['type_id'=>1,'vod_name'=>'Lifecycle Merge Primary','vod_play_from'=>'dplayer','vod_play_url'=>'1$https://media.example.com/p.m3u8','vod_content'=>'','vod_down_url'=>'','vod_plot_name'=>'','vod_plot_detail'=>'']);
$secondaryId=(int)\think\Db::name('vod')->insertGetId(['type_id'=>1,'vod_name'=>'Lifecycle Merge Secondary','vod_play_from'=>'dplayer','vod_play_url'=>'2$https://media.example.com/s.m3u8','vod_content'=>'','vod_down_url'=>'','vod_plot_name'=>'','vod_plot_detail'=>'']);
\think\Db::name('vod_ext')->insert(['vod_id'=>$primaryId,'public_id'=>'LCM234','old_titles_json'=>'["Primary Old"]','workflow_status'=>'duplicate_review','created_at'=>$now,'updated_at'=>$now]);
\think\Db::name('vod_ext')->insert(['vod_id'=>$secondaryId,'public_id'=>'LCS234','old_titles_json'=>'["Secondary Old"]','workflow_status'=>'duplicate_review','created_at'=>$now,'updated_at'=>$now]);
\think\Db::name('vod_meta_term')->insertAll([['vod_id'=>$primaryId,'term_id'=>$regionId,'created_at'=>$now],['vod_id'=>$secondaryId,'term_id'=>$genreId,'created_at'=>$now]]);
\think\Db::name('content_lang')->insertAll([
 ['content_type'=>'vod','content_id'=>$primaryId,'lang_code'=>'en','data'=>'{"vod_name":"Primary"}','status'=>1,'source'=>'manual','update_time'=>$now],
 ['content_type'=>'vod','content_id'=>$secondaryId,'lang_code'=>'zh-TW','data'=>'{"vod_name":"次要"}','status'=>1,'source'=>'manual','update_time'=>$now],
]);
$item='lifecycle-'.$primaryId;
\think\Db::name('ext_source_map')->insertAll([
 ['provider_code'=>'tmdb','item_key'=>$item,'cms_mid'=>1,'cms_id'=>$primaryId,'map_confidence'=>1,'map_time_add'=>$now,'map_time_update'=>$now],
 ['provider_code'=>'imdb','item_key'=>$item.'-secondary','cms_mid'=>1,'cms_id'=>$secondaryId,'map_confidence'=>1,'map_time_add'=>$now,'map_time_update'=>$now],
]);
$mergeCandidate=(int)\think\Db::name('content_duplicate_candidate')->insertGetId(['vod_id_low'=>min($primaryId,$secondaryId),'vod_id_high'=>max($primaryId,$secondaryId),'evidence_json'=>'[]','score'=>1000,'decision'=>'pending','created_at'=>$now,'updated_at'=>$now]);
$before=[
 'primary_play'=>(string)\think\Db::name('vod')->where('vod_id',$primaryId)->value('vod_play_url'),
 'secondary_play'=>(string)\think\Db::name('vod')->where('vod_id',$secondaryId)->value('vod_play_url'),
 'primary_terms'=>(int)\think\Db::name('vod_meta_term')->where('vod_id',$primaryId)->count(),
 'secondary_terms'=>(int)\think\Db::name('vod_meta_term')->where('vod_id',$secondaryId)->count(),
 'primary_lang'=>(int)\think\Db::name('content_lang')->where(['content_type'=>'vod','content_id'=>$primaryId])->count(),
 'secondary_lang'=>(int)\think\Db::name('content_lang')->where(['content_type'=>'vod','content_id'=>$secondaryId])->count(),
];
$snapshotId=(new \app\common\util\DuplicateMergeService(static fn():int=>$now+20,new \app\common\util\ContentWorkflowCoordinator(null,null,null,null,static fn():int=>$now+20)))->merge($mergeCandidate,$primaryId,$secondaryId,101);
lifecycle_assert((int)\think\Db::name('vod_meta_term')->where('vod_id',$primaryId)->count()===2,'merge did not union taxonomy');
lifecycle_assert((int)\think\Db::name('content_lang')->where(['content_type'=>'vod','content_id'=>$primaryId])->count()===2,'merge did not preserve locales');
(new \app\common\util\DuplicateRestoreService(static fn():int=>$now+30))->restore($snapshotId,101,true,static fn(int $reviewerId,string $permission):bool=>$reviewerId===101&&$permission==='content_duplicate_restore');
$after=[
 'primary_play'=>(string)\think\Db::name('vod')->where('vod_id',$primaryId)->value('vod_play_url'),
 'secondary_play'=>(string)\think\Db::name('vod')->where('vod_id',$secondaryId)->value('vod_play_url'),
 'primary_terms'=>(int)\think\Db::name('vod_meta_term')->where('vod_id',$primaryId)->count(),
 'secondary_terms'=>(int)\think\Db::name('vod_meta_term')->where('vod_id',$secondaryId)->count(),
 'primary_lang'=>(int)\think\Db::name('content_lang')->where(['content_type'=>'vod','content_id'=>$primaryId])->count(),
 'secondary_lang'=>(int)\think\Db::name('content_lang')->where(['content_type'=>'vod','content_id'=>$secondaryId])->count(),
];
lifecycle_assert($after===$before,'merge restoration did not return relations and playback to the original projection');
lifecycle_assert(\think\Db::name('content_merge_snapshot')->where('merge_snapshot_id',$snapshotId)->value('status')==='restored','merge snapshot was not marked restored');

fwrite(STDOUT,"OK: complete content lifecycle and reversible merge passed on {$database}\n");
};
try{$run();}catch(\Throwable $exception){fwrite(STDERR,'FAIL: uncaught lifecycle exception: '.get_class($exception).': '.$exception->getMessage().PHP_EOL);exit(1);}
