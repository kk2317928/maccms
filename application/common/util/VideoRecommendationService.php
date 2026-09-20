<?php
namespace app\common\util;

final class VideoRecommendationService
{
    public function recommend(array $seed,array $candidates,$limit=12,$now=null): array
    {
        $now=$now===null?time():(int)$now; $out=[];
        foreach($candidates as $candidate){
            if(empty($candidate['available']) || (int)($candidate['vod_id']??0)===(int)($seed['vod_id']??0))continue;
            $score=0; $reasons=[];
            $weights=['genres'=>8,'regions'=>4,'tags'=>5,'people'=>7];
            $labels=['genres'=>'shared_genre','regions'=>'shared_region','tags'=>'shared_tag','people'=>'shared_person'];
            foreach($weights as $field=>$weight){
                $shared=array_values(array_intersect(array_map('strval',$seed[$field]??[]),array_map('strval',$candidate[$field]??[])));
                sort($shared,SORT_STRING);
                foreach($shared as $value){$score+=$weight;$reasons[]=$labels[$field].':'.$value;}
            }
            $pop=min(20,max(0,(int)($candidate['popularity']??0))); $score+=$pop;
            if((int)($candidate['published_at']??0)>=$now-30*86400){$score+=3;$reasons[]='fresh';}
            $out[]=['public_id'=>(string)$candidate['public_id'],'score'=>$score,'reasons'=>$reasons,'_vod'=>(int)$candidate['vod_id']];
        }
        usort($out,function($a,$b){return $a['score']===$b['score']?$a['_vod']<=>$b['_vod']:$b['score']<=>$a['score'];});
        $out=array_slice($out,0,max(1,min(50,(int)$limit)));
        foreach($out as &$row)unset($row['_vod']);
        return $out;
    }
}
