<?php
declare(strict_types=1);
require_once __DIR__.'/../../application/common/util/ContentRepairService.php';
use app\common\util\ContentRepairService;
function repairFail($m){fwrite(STDERR,"FAIL: $m\n");exit(1);}function repairAssert($c,$m){if(!$c)repairFail($m);}function repairThrows($f,$m){try{$f();}catch(\InvalidArgumentException $e){return;}repairFail($m);}
$rows=[
 ['vod_id'=>1,'has_extension'=>0,'workflow_status'=>'','merged_into_vod_id'=>0,'manual_locks'=>0,'has_ai_job'=>0,'vod_area'=>'台灣','vod_class'=>'劇情','vod_tag'=>'成長'],
 ['vod_id'=>2,'has_extension'=>1,'workflow_status'=>'published','merged_into_vod_id'=>0,'manual_locks'=>1,'has_ai_job'=>1,'vod_area'=>'日本'],
 ['vod_id'=>3,'has_extension'=>1,'workflow_status'=>'merged','merged_into_vod_id'=>1,'manual_locks'=>0,'has_ai_job'=>0,'vod_area'=>'韓國'],
];
$effects=[];$service=new ContentRepairService(function($limit)use($rows){repairAssert($limit===25,'batch limit mismatch');return $rows;},function($id,$state)use(&$effects){$effects[]=['repair',$id,$state];},function($id,$taxonomy)use(&$effects){$effects[]=['taxonomy',$id,$taxonomy];},function($id)use(&$effects){$effects[]=['enqueue',$id];},function($callback){return $callback();});
$dry=$service->run(false,false,25);repairAssert($effects===[],'dry-run must never mutate');repairAssert($dry['scanned']===3&&$dry['extensions']===1&&$dry['ai_jobs']===1,'dry-run counts mismatch');
repairThrows(function()use($service){$service->run(true,false,25);},'apply must require explicit confirmation');
$applied=$service->run(true,true,25);repairAssert($applied['repaired']===1,'safe row was not repaired');
repairAssert(in_array(['repair',1,'imported'],$effects,true),'safe workflow repair missing');repairAssert(in_array(['enqueue',1],$effects,true),'missing AI job was not enqueued');
foreach($effects as $effect)repairAssert($effect[1]!==2&&$effect[1]!==3,'published, locked or merged rows must not mutate');
$command=@file_get_contents(__DIR__.'/../../application/command/MaccmsRepairContent.php')?:'';$registry=@file_get_contents(__DIR__.'/../../application/command.php')?:'';
repairAssert(strpos($command,"addOption('apply'")!==false&&strpos($command,"addOption('confirm'")!==false,'repair command safety options missing');repairAssert(strpos($registry,'MaccmsRepairContent')!==false,'repair command is not registered');
fwrite(STDOUT,"Content repair command regression passed.\n");
