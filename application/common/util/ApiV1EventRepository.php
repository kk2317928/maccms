<?php
namespace app\common\util;

use think\Db;

final class ApiV1EventRepository
{
    public function ingestBatch(array $events,$actorKey,$userId,$receivedAt,VideoEventPolicy $policy): array
    {
        $lock='api_v1_event_'.sha1((string)$actorKey);
        $row=Db::query('SELECT GET_LOCK(?, 5) AS acquired',[$lock]);
        if(empty($row)||(int)$row[0]['acquired']!==1)throw new \RuntimeException('event_lock');
        try {
            $current=$this->actorCountSince($actorKey,(int)$receivedAt-60);
            if(!$policy->allowActorRate($current,count($events)))return ['rate_limited'=>true,'accepted'=>0,'duplicates'=>0];
            return Db::transaction(function()use($events,$actorKey,$userId,$receivedAt){
                $accepted=0;$duplicates=0;
                foreach($events as $event){$result=$this->insert($event,$actorKey,$userId,$receivedAt);$result['accepted']?$accepted++:$duplicates++;}
                return ['rate_limited'=>false,'accepted'=>$accepted,'duplicates'=>$duplicates];
            });
        } finally { try{Db::query('SELECT RELEASE_LOCK(?)',[$lock]);}catch(\Throwable $ignored){} }
    }

    public function insert(array $event,$actorKey,$userId,$receivedAt): array
    {
        $video=Db::name('vod')->alias('v')->join('__VOD_EXT__ e','e.vod_id=v.vod_id')
            ->field('v.vod_id')->where(ApiV1CatalogRepository::PUBLICATION_SQL)->where('e.public_id',$event['public_id'])->find();
        if(!$video)return ['accepted'=>false,'duplicate'=>false,'reason'=>'not_found'];
        $dedupe=(new ApiV1EventDeduplicator())->fingerprint($event,$actorKey);$prefix=Db::getConfig('prefix');
        $sql='INSERT INTO `'.$prefix.'api_video_event` (`dedupe_hash`,`event_type`,`actor_key`,`user_id`,`vod_id`,`episode`,`position_seconds`,`duration_seconds`,`occurred_at`,`received_at`) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE `event_id`=LAST_INSERT_ID(`event_id`)';
        $affected=Db::execute($sql,[$dedupe,$event['event_type'],$actorKey,(int)$userId,(int)$video['vod_id'],$event['episode'],$event['position_seconds'],$event['duration_seconds'],$event['occurred_at'],(int)$receivedAt]);
        return ['accepted'=>$affected===1,'duplicate'=>$affected!==1];
    }

    public function actorCountSince($actorKey,$since): int
    {
        return (int)Db::name('api_video_event')->where('actor_key',(string)$actorKey)->where('received_at','>=',(int)$since)->count();
    }
}
