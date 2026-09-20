<?php
declare(strict_types=1);
function pol_ok($c,string $m):void{if(!$c){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
$root=dirname(__DIR__,2);
require_once $root.'/application/common/util/VideoEventPolicy.php';
use app\common\util\VideoEventPolicy;
$p=new VideoEventPolicy(90,120,20);
$now=2000000000;
pol_ok($p->rawCutoff($now)===$now-90*86400,'90-day raw retention');
pol_ok($p->acceptsOccurredAt($now-300,$now),'recent delayed event accepted');
pol_ok(!$p->acceptsOccurredAt($now-86401,$now),'stale event rejected');
pol_ok(!$p->acceptsOccurredAt($now+301,$now),'far future event rejected');
pol_ok($p->allowBatch(20) && !$p->allowBatch(21),'batch cap');
pol_ok($p->allowActorRate(119) && !$p->allowActorRate(120),'actor minute rate cap');
$sql=$p->purgeSql('mac_',$now);
pol_ok(strpos($sql,'DELETE FROM `mac_api_video_event`')===0,'purge targets only raw event table');
pol_ok(strpos($sql,(string)$p->rawCutoff($now))!==false,'purge uses cutoff');
fwrite(STDOUT,"Video event retention and abuse policy passed.\n");
