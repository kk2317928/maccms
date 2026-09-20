<?php
namespace app\common\util;

final class VideoRankingWindows
{
    private const WEIGHTS=['play_start'=>1,'valid_watch'=>3,'progress'=>0,'completion'=>4,'favorite'=>4];

    public static function aggregate(array $events,$now): array
    {
        $now=(int)$now;
        $today=strtotime(gmdate('Y-m-d',$now).' 00:00:00 UTC');
        $starts=['today'=>$today,'days_7'=>$today-6*86400,'days_30'=>$today-29*86400];
        $out=[];
        foreach($events as $event){
            $vod=(int)($event['vod_id']??0); $type=(string)($event['event_type']??'');
            if($vod<=0 || !isset(self::WEIGHTS[$type])) continue;
            $score=self::WEIGHTS[$type]; $at=(int)($event['occurred_at']??0);
            if(!isset($out[$vod]))$out[$vod]=['today'=>0,'days_7'=>0,'days_30'=>0,'all_time'=>0];
            $out[$vod]['all_time']+=$score;
            foreach($starts as $key=>$start)if($at>=$start && $at<=$now)$out[$vod][$key]+=$score;
        }
        ksort($out,SORT_NUMERIC);
        return $out;
    }

    public static function rank(array $aggregate,$window): array
    {
        if(!in_array($window,['today','days_7','days_30','all_time'],true)) throw new \InvalidArgumentException('Invalid ranking window.');
        $rows=[]; foreach($aggregate as $vod=>$scores)$rows[]=['vod_id'=>(int)$vod,'score'=>(int)$scores[$window]];
        usort($rows,function($a,$b){return $a['score']===$b['score']?$a['vod_id']<=>$b['vod_id']:$b['score']<=>$a['score'];});
        return $rows;
    }
}
