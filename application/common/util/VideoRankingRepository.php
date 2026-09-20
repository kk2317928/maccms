<?php
namespace app\common\util;

use think\Db;

final class VideoRankingRepository
{
    public function aggregate($now=null): array
    {
        $now=$now===null?time():(int)$now;$date=gmdate('Y-m-d',$now);$prefix=Db::getConfig('prefix');
        return Db::transaction(function()use($now,$date,$prefix){
            $state=Db::query('SELECT `last_event_id` FROM `'.$prefix.'api_video_rank_state` WHERE `state_id`=1 FOR UPDATE');
            $last=empty($state)?0:(int)$state[0]['last_event_id'];
            $new=Db::name('api_video_event')->where('event_id','>',$last)->order('event_id asc')->select();
            $max=$last;$increments=[];
            foreach($new as $event){$max=max($max,(int)$event['event_id']);$vod=(int)$event['vod_id'];$weight=$this->weight($event['event_type']);$increments[$vod]=($increments[$vod]??0)+$weight;}
            $events=Db::name('api_video_event')->field('vod_id,event_type,occurred_at')->where('occurred_at','<=',$now)->select();
            $windows=VideoRankingWindows::aggregate($events,$now);
            foreach($windows as $vod=>$scores){
                $existing=Db::name('api_video_rank')->where(['stat_date'=>$date,'vod_id'=>$vod])->find();
                $all=(int)($existing['all_time_score']??0)+(int)($increments[$vod]??0);
                $sql='INSERT INTO `'.$prefix.'api_video_rank` (`stat_date`,`vod_id`,`today_score`,`days_7_score`,`days_30_score`,`all_time_score`,`updated_at`) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `today_score`=VALUES(`today_score`),`days_7_score`=VALUES(`days_7_score`),`days_30_score`=VALUES(`days_30_score`),`all_time_score`=VALUES(`all_time_score`),`updated_at`=VALUES(`updated_at`)';
                Db::execute(str_replace('\\`','`',$sql),[$date,$vod,$scores['today'],$scores['days_7'],$scores['days_30'],$all,$now]);
            }
            Db::execute('INSERT INTO `'.$prefix.'api_video_rank_state` (`state_id`,`last_event_id`,`updated_at`) VALUES (1,?,?) ON DUPLICATE KEY UPDATE `last_event_id`=VALUES(`last_event_id`),`updated_at`=VALUES(`updated_at`)',[$max,$now]);
            return ['processed'=>count($new),'last_event_id'=>$max];
        });
    }
    private function weight($type):int{return ['play_start'=>1,'valid_watch'=>3,'progress'=>0,'completion'=>4,'favorite'=>4][(string)$type]??0;}
}
