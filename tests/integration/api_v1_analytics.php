<?php
declare(strict_types=1);
$db=(string)getenv('MACCMS_TEST_DATABASE');
if(!preg_match('/^maccms_ci_[a-z0-9_]+$/',$db)){fwrite(STDERR,"FAIL: disposable database required\n");exit(1);}
$pdo=new PDO('mysql:host='.(getenv('MACCMS_TEST_HOST')?:'127.0.0.1').';port='.(getenv('MACCMS_TEST_PORT')?:'3306').';dbname='.$db.';charset=utf8mb4',getenv('MACCMS_TEST_USER')?:'root',getenv('MACCMS_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$now=time();$hash=str_repeat('a',64);
$sql="INSERT INTO mac_api_video_event (dedupe_hash,event_type,actor_key,user_id,vod_id,episode,position_seconds,duration_seconds,occurred_at,received_at) VALUES (?,?,?,?,?,?,?,?,?,?)";
$pdo->prepare($sql)->execute([$hash,'play_start','device:'.str_repeat('b',64),0,7,'',0,0,$now,$now]);
try{$pdo->prepare($sql)->execute([$hash,'play_start','device:'.str_repeat('b',64),0,7,'',0,0,$now,$now]);fwrite(STDERR,"FAIL: replay inflated raw events\n");exit(1);}catch(PDOException $e){if((string)$e->getCode()!=='23000')throw $e;}
if((int)$pdo->query("SELECT COUNT(*) FROM mac_api_video_event")->fetchColumn()!==1){fwrite(STDERR,"FAIL: event dedupe count\n");exit(1);}
$futureHash=str_repeat('c',64);$pdo->prepare($sql)->execute([$futureHash,'completion','device:'.str_repeat('d',64),0,8,'e1',0,100,$now+300,$now]);
if((int)$pdo->query("SELECT COUNT(*) FROM mac_api_video_event WHERE aggregated_at IS NULL")->fetchColumn()!==2){fwrite(STDERR,"FAIL: unprocessed events not tracked\n");exit(1);}
$pdo->exec("INSERT INTO mac_api_video_rank_total (vod_id,all_time_score,updated_at) VALUES (7,10,{$now})");
$yesterday=gmdate('Y-m-d',$now-86400);$today=gmdate('Y-m-d',$now);
$pdo->exec("INSERT INTO mac_api_video_rank (stat_date,vod_id,today_score,days_7_score,days_30_score,all_time_score,updated_at) VALUES ('{$yesterday}',7,10,10,10,10,{$now}),('{$today}',7,0,10,10,10,{$now})");
if((int)$pdo->query("SELECT all_time_score FROM mac_api_video_rank WHERE stat_date='{$today}' AND vod_id=7")->fetchColumn()!==10){fwrite(STDERR,"FAIL: all-time score reset across UTC date\n");exit(1);}
$pdo->exec("UPDATE mac_api_video_event SET received_at=".($now-100*86400)." WHERE dedupe_hash='{$hash}'");
$pdo->exec("DELETE FROM mac_api_video_event WHERE aggregated_at IS NOT NULL AND received_at<".($now-90*86400));
if((int)$pdo->query("SELECT COUNT(*) FROM mac_api_video_event WHERE dedupe_hash='{$hash}'")->fetchColumn()!==1){fwrite(STDERR,"FAIL: purge deleted unprocessed event\n");exit(1);}
fwrite(STDOUT,"OK: API v1 analytics database boundaries passed for {$db}.\n");
