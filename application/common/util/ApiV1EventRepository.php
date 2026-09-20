<?php
namespace app\common\util;

use think\Db;

final class ApiV1EventRepository
{
    public function insert(array $event,$actorKey,$userId,$receivedAt): array
    {
        $video=Db::name('vod')->alias('v')->join('__VOD_EXT__ e','e.vod_id=v.vod_id')
            ->field('v.vod_id')->where(ApiV1CatalogRepository::PUBLICATION_SQL)->where('e.public_id',$event['public_id'])->find();
        if(!$video)return ['accepted'=>false,'reason'=>'not_found'];
        $dedupe=(new ApiV1EventDeduplicator())->fingerprint($event,$actorKey);
        $prefix=Db::getConfig('prefix');
        $sql='INSERT IGNORE INTO `'.$prefix.'api_video_event` (`dedupe_hash`,`event_type`,`actor_key`,`user_id`,`vod_id`,`episode`,`position_seconds`,`duration_seconds`,`occurred_at`,`received_at`) VALUES (?,?,?,?,?,?,?,?,?,?)';
        $affected=Db::execute($sql,[$dedupe,$event['event_type'],$actorKey,(int)$userId,(int)$video['vod_id'],$event['episode'],$event['position_seconds'],$event['duration_seconds'],$event['occurred_at'],(int)$receivedAt]);
        return ['accepted'=>$affected===1,'duplicate'=>$affected===0];
    }

    public function actorCountSince($actorKey,$since): int
    {
        return (int)Db::name('api_video_event')->where('actor_key',(string)$actorKey)->where('received_at','>=',(int)$since)->count();
    }
}
