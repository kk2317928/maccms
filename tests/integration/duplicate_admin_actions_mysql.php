<?php

declare(strict_types=1);

$database=(string)getenv('MACCMS_TEST_DATABASE');
if(!preg_match('/^maccms_ci_[a-z0-9_]+$/',$database)){fwrite(STDERR,"FAIL: disposable database required\n");exit(1);}
$root=dirname(__DIR__,2); define('APP_PATH',$root.'/application/'); define('ENTRANCE','command');
$_SERVER['HTTP_HOST']='127.0.0.1';$_SERVER['SCRIPT_NAME']='/duplicate-admin-actions';
require $root.'/thinkphp/base.php'; \think\App::initCommon();

function dupActionAssert($ok,string $msg):void{if(!$ok){fwrite(STDERR,"FAIL: {$msg}\n");exit(1);}}
$now=1700000000;
$make=function(string $name,string $pid)use($now):int{
 $id=(int)\think\Db::name('vod')->insertGetId(['type_id'=>0,'vod_name'=>$name,'vod_content'=>'','vod_play_from'=>'dplayer','vod_play_url'=>'1$https://media.invalid/'.$pid.'.m3u8','vod_down_url'=>'','vod_plot_name'=>'','vod_plot_detail'=>'']);
 \think\Db::name('vod_ext')->insert(['vod_id'=>$id,'public_id'=>$pid,'old_titles_json'=>'[]','workflow_status'=>'duplicate_review','created_at'=>$now,'updated_at'=>$now]);
 return $id;
};
$p=$make('Conflict Primary','Ab3xY9');$s=$make('Conflict Secondary','Cd4wZ8');
$c=(int)\think\Db::name('content_duplicate_candidate')->insertGetId(['vod_id_low'=>min($p,$s),'vod_id_high'=>max($p,$s),'evidence_json'=>'[]','score'=>999,'decision'=>'pending','created_at'=>$now,'updated_at'=>$now]);
$snapshot=(new \app\common\util\DuplicateMergeService(fn()=>$now+10))->merge($c,$p,$s,1);
\think\Db::name('vod')->where('vod_id',$p)->update(['vod_name'=>'Edited after merge']);
$beforeP=\think\Db::name('vod')->where('vod_id',$p)->find();$beforeS=\think\Db::name('vod')->where('vod_id',$s)->find();$beforeSnap=\think\Db::name('content_merge_snapshot')->where('merge_snapshot_id',$snapshot)->find();
try{(new \app\common\util\DuplicateRestoreService(fn()=>$now+20))->restore($snapshot,1,true,fn()=>true);dupActionAssert(false,'conflicted restore unexpectedly succeeded');}catch(RuntimeException $e){dupActionAssert(stripos($e->getMessage(),'conflict')!==false,'conflicted restore did not report conflict');}
dupActionAssert(\think\Db::name('vod')->where('vod_id',$p)->find()===$beforeP,'conflict changed primary');
dupActionAssert(\think\Db::name('vod')->where('vod_id',$s)->find()===$beforeS,'conflict changed secondary');
dupActionAssert(\think\Db::name('content_merge_snapshot')->where('merge_snapshot_id',$snapshot)->find()===$beforeSnap,'conflict changed active snapshot');

$p2=$make('Roundtrip Primary','Ef5vT7');$s2=$make('Roundtrip Secondary','Gh6uR4');
$c2=(int)\think\Db::name('content_duplicate_candidate')->insertGetId(['vod_id_low'=>min($p2,$s2),'vod_id_high'=>max($p2,$s2),'evidence_json'=>'[]','score'=>998,'decision'=>'pending','created_at'=>$now,'updated_at'=>$now]);
$origP=\think\Db::name('vod')->where('vod_id',$p2)->find();$origS=\think\Db::name('vod')->where('vod_id',$s2)->find();
$snap2=(new \app\common\util\DuplicateMergeService(fn()=>$now+30))->merge($c2,$p2,$s2,1);
(new \app\common\util\DuplicateRestoreService(fn()=>$now+40))->restore($snap2,1,true,fn()=>true);
dupActionAssert(\think\Db::name('vod')->where('vod_id',$p2)->find()===$origP,'roundtrip primary mismatch');
dupActionAssert(\think\Db::name('vod')->where('vod_id',$s2)->find()===$origS,'roundtrip secondary mismatch');
dupActionAssert(\think\Db::name('content_merge_snapshot')->where('merge_snapshot_id',$snap2)->value('status')==='restored','roundtrip snapshot not restored');

fwrite(STDOUT,"PASS: duplicate admin merge/restore conflict reliability on {$database}.\n");
