<?php
declare(strict_types=1);
$root=dirname(__DIR__,2); foreach(['ContentAdminPolicy.php','ContentAdminAudit.php','ContentJobAdminService.php'] as $f){require_once $root.'/application/common/util/'.$f;}
use app\common\util\ContentAdminAudit; use app\common\util\ContentJobAdminService;
function cjac($v,$m){if(!$v){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
$jobs=[1=>['job_id'=>1,'job_type'=>'ai.normalize','status'=>'queued'],2=>['job_id'=>2,'job_type'=>'tmdb_match','status'=>'paused'],3=>['job_id'=>3,'job_type'=>'ai.normalize','status'=>'running']]; $events=[];
$audit=new ContentAdminAudit(static function($r)use(&$events){$events[]=$r;return count($events);},static fn()=>100);
$transition=static function($id,$action,$reason,$now,$authorize)use(&$jobs){$before=$jobs[$id];$authorize($before);$allowed=['pause'=>['queued','paused'],'resume'=>['paused','queued'],'skip'=>['queued','skipped']];if(!isset($allowed[$action])||$before['status']!==$allowed[$action][0])throw new RuntimeException('invalid state');if($action==='skip'&&trim($reason)==='')throw new InvalidArgumentException('reason required');$jobs[$id]=array_merge($before,['status'=>$allowed[$action][1],'error_summary'=>$action==='skip'?$reason:'']);return [$before,$jobs[$id]];};
$service=new ContentJobAdminService($audit,null,null,null,null,static fn($cb)=>$cb(),static fn()=>100,$transition);
$service->control(1,'pause','',9,'editor',['content_workspace/run_ai'],true); cjac($jobs[1]['status']==='paused','queued job was not paused');
$service->control(2,'resume','',9,'editor',['content_workspace/run_tmdb'],true); cjac($jobs[2]['status']==='queued','paused job was not resumed');
try{$service->control(3,'pause','',9,'editor',['content_workspace/run_ai'],true);cjac(false,'running job was interrupted');}catch(RuntimeException $e){}
try{$service->control(1,'skip','',9,'editor',['content_workspace/run_ai'],true);cjac(false,'skip accepted empty reason');}catch(Throwable $e){}
cjac(count($events)===2&&$events[0]['event_code']==='content.job.paused'&&$events[1]['event_code']==='content.job.resumed','immutable control audits missing');
fwrite(STDOUT,"OK: content job admin controls passed.\n");
