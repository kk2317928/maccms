<?php
namespace app\common\util;

use think\Db;

final class ApiV1DiscoveryRepository
{
    public function rankings($window='days_7',$limit=20): array
    {
        $columns=['today'=>'r.today_score','days_7'=>'r.days_7_score','days_30'=>'r.days_30_score','all_time'=>'r.all_time_score'];
        if(!isset($columns[$window]))throw new \InvalidArgumentException('window');
        $limit=max(1,min(50,(int)$limit));$column=$columns[$window];
        $rows=Db::name('api_video_rank')->alias('r')->join('__VOD__ v','v.vod_id=r.vod_id')
            ->join('__VOD_EXT__ e','e.vod_id=v.vod_id')->field('e.public_id,v.vod_name as title,v.vod_pic as poster,'.$column.' as score')
            ->where(ApiV1CatalogRepository::PUBLICATION_SQL)->where('r.stat_date',gmdate('Y-m-d'))
            ->order($column.' desc,r.vod_id asc')->limit($limit)->select();
        return array_map(function($row){return ['public_id'=>(string)$row['public_id'],'title'=>(string)$row['title'],'poster'=>(string)$row['poster'],'score'=>(int)$row['score']];},$rows);
    }

    public function recommendations($publicId,$limit=12): ?array
    {
        $seed=$this->featureRow($publicId);
        if($seed===null)return null;
        $rows=Db::name('vod')->alias('v')->join('__VOD_EXT__ e','e.vod_id=v.vod_id')
            ->field('v.vod_id,e.public_id,v.vod_area,v.vod_class,v.vod_tag,v.vod_actor,v.vod_director,v.vod_hits,e.published_at')
            ->where(ApiV1CatalogRepository::PUBLICATION_SQL)->where('v.vod_id','<>',$seed['vod_id'])
            ->order('v.vod_hits desc,v.vod_id asc')->limit(200)->select();
        $candidates=[];
        foreach($rows as $row)$candidates[]=$this->features($row,true);
        return (new VideoRecommendationService())->recommend($this->features($seed,true),$candidates,$limit,time());
    }

    private function featureRow($publicId)
    {
        $row=Db::name('vod')->alias('v')->join('__VOD_EXT__ e','e.vod_id=v.vod_id')
            ->field('v.vod_id,e.public_id,v.vod_area,v.vod_class,v.vod_tag,v.vod_actor,v.vod_director,v.vod_hits,e.published_at')
            ->where(ApiV1CatalogRepository::PUBLICATION_SQL)->where('e.public_id',strtoupper((string)$publicId))->find();
        return $row?:null;
    }

    private function features(array $row,$available): array
    {
        $split=function($value){$parts=preg_split('/[,\/]+/u',(string)$value,-1,PREG_SPLIT_NO_EMPTY);$parts=array_values(array_unique(array_map('trim',$parts)));sort($parts,SORT_STRING);return $parts;};
        return ['vod_id'=>(int)$row['vod_id'],'public_id'=>(string)$row['public_id'],'genres'=>$split($row['vod_class']??''),'regions'=>$split($row['vod_area']??''),'tags'=>$split($row['vod_tag']??''),'people'=>array_values(array_unique(array_merge($split($row['vod_actor']??''),$split($row['vod_director']??'')))),'popularity'=>(int)($row['vod_hits']??0),'published_at'=>(int)$row['published_at'],'available'=>(bool)$available];
    }
}
