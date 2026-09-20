<?php
namespace app\common\util;

use think\Db;

final class VideoRankingRepository
{
    public function aggregate($now=null): array
    {
        $now=$now===null?time():(int)$now;$date=gmdate('Y-m-d',$now);$prefix=Db::getConfig('prefix');
        return Db::transaction(function()use($now,$date,$prefix){
            $new=Db::name('api_video_event')->whereNull('aggregated_at')->where('occurred_at','<=',$now)
                ->order('event_id asc')->limit(1000)->lock(true)->select();
            $ids=[];
            foreach($new as $event){
                $ids[]=(int)$event['event_id'];$weight=$this->weight($event['event_type']);
                if($weight===0)continue;
                $sql='INSERT INTO `'.$prefix.'api_video_rank_total` (`vod_id`,`all_time_score`,`updated_at`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `all_time_score`=`all_time_score`+VALUES(`all_time_score`),`updated_at`=VALUES(`updated_at`)';
                Db::execute($sql,[(int)$event['vod_id'],$weight,$now]);
            }
            if($ids)Db::name('api_video_event')->where('event_id','in',$ids)->update(['aggregated_at'=>$now]);
            $recent=Db::name('api_video_event')->field('vod_id,event_type,occurred_at')->where('occurred_at','between',[$now-30*86400,$now])->select();
            $windows=VideoRankingWindows::aggregate($recent,$now);
            $totals=Db::name('api_video_rank_total')->field('vod_id,all_time_score')->select();
            foreach($totals as $total){
                $vod=(int)$total['vod_id'];$scores=$windows[$vod]??['today'=>0,'days_7'=>0,'days_30'=>0];
                $sql='INSERT INTO `'.$prefix.'api_video_rank` (`stat_date`,`vod_id`,`today_score`,`days_7_score`,`days_30_score`,`all_time_score`,`updated_at`) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `today_score`=VALUES(`today_score`),`days_7_score`=VALUES(`days_7_score`),`days_30_score`=VALUES(`days_30_score`),`all_time_score`=VALUES(`all_time_score`),`updated_at`=VALUES(`updated_at`)';
                Db::execute($sql,[$date,$vod,$scores['today'],$scores['days_7'],$scores['days_30'],(int)$total['all_time_score'],$now]);
            }
            return ['processed'=>count($new),'pending'=>count($new)===1000];
        });
    }

    public function purge($now=null): int
    {
        $now=$now===null?time():(int)$now;$cutoff=(new VideoEventPolicy())->rawCutoff($now);
        return (int)Db::name('api_video_event')->whereNotNull('aggregated_at')->where('received_at','<',$cutoff)->limit(1000)->delete();
    }

    private function weight($type):int{return ['play_start'=>1,'valid_watch'=>3,'progress'=>0,'completion'=>4,'favorite'=>4][(string)$type]??0;}
}
