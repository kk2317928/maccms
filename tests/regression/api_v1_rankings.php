<?php
declare(strict_types=1);
function rank_ok($c,string $m):void{if(!$c){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
$root=dirname(__DIR__,2);
require_once $root.'/application/common/util/VideoRankingWindows.php';
use app\common\util\VideoRankingWindows;

$now=strtotime('2026-09-20 12:00:00 UTC');
$events=[
 ['vod_id'=>1,'event_type'=>'valid_watch','occurred_at'=>$now-60],
 ['vod_id'=>1,'event_type'=>'completion','occurred_at'=>$now-8*86400],
 ['vod_id'=>2,'event_type'=>'favorite','occurred_at'=>$now-6*86400],
 ['vod_id'=>2,'event_type'=>'play_start','occurred_at'=>$now-31*86400],
];
$rows=VideoRankingWindows::aggregate($events,$now);
rank_ok($rows[1]['today']===3 && $rows[1]['days_7']===3 && $rows[1]['days_30']===7 && $rows[1]['all_time']===7,'weighted windows for video 1');
rank_ok($rows[2]['today']===0 && $rows[2]['days_7']===4 && $rows[2]['days_30']===4 && $rows[2]['all_time']===5,'boundary windows for video 2');
rank_ok(VideoRankingWindows::rank($rows,'days_30')===[['vod_id'=>1,'score'=>7],['vod_id'=>2,'score'=>4]],'stable score/id ordering');
$m=file_get_contents($root.'/application/data/migrations/20260920000200_api_video_rankings.sql');
rank_ok($m!==false,'ranking migration exists');
foreach(['api_video_rank','stat_date','today_score','days_7_score','days_30_score','all_time_score','UNIQUE KEY'] as $n)rank_ok(stripos($m,$n)!==false,"migration contains {$n}");
fwrite(STDOUT,"Video ranking windows passed.\n");
