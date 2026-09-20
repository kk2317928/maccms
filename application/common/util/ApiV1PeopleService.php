<?php
namespace app\common\util;

use think\Db;

final class ApiV1PeopleService
{
    private $loadPeople;
    private $loadVideos;

    public function __construct(callable $loadPeople=null,callable $loadVideos=null)
    {
        $this->loadPeople=$loadPeople?:static function($actorId){return Db::name('actor')->where(['actor_id'=>(int)$actorId,'actor_status'=>1])->field('actor_id,actor_name,actor_alias,actor_pic,actor_content,actor_area,actor_sex,actor_status')->find();};
        $this->loadVideos=$loadVideos?:static function($name){
            $rows=Db::name('vod')->alias('v')->join(config('database.prefix').'vod_ext e','e.vod_id=v.vod_id')->where(['v.vod_status'=>1,'e.workflow_status'=>'published','e.merged_into_vod_id'=>null])->where(function($query)use($name){$query->whereLike('v.vod_actor','%'.$name.'%')->whereOr('v.vod_director','like','%'.$name.'%');})->field('v.vod_name,v.vod_actor,v.vod_director,v.vod_pic,v.vod_year,v.vod_remarks,v.vod_score,e.public_id,e.title_tw,e.title_cn,e.title_en,e.original_title,e.poster_s3,e.published_at,e.workflow_status,e.merged_into_vod_id,v.vod_status')->limit(100)->select();
            return array_values(array_filter($rows,static function($row)use($name){return self::csvContains($row['vod_actor']??'',$name)||self::csvContains($row['vod_director']??'',$name);}));
        };
    }

    public static function slug($actorId)
    {
        $value=(((int)$actorId*15485863)+32452843)%1073741824;$alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';$slug='';
        for($i=0;$i<6;$i++){$slug=$alphabet[$value&31].$slug;$value=intdiv($value,32);}return $slug;
    }

    public function detail($slug,ApiV1Locale $locale)
    {
        $actorId=self::actorId($slug);if($actorId<=0)return null;$person=call_user_func($this->loadPeople,$actorId);
        if(!is_array($person)||(int)($person['actor_status']??0)!==1)return null;$videos=[];
        foreach((array)call_user_func($this->loadVideos,(string)$person['actor_name']) as $row){if((int)($row['vod_status']??0)!==1||(string)($row['workflow_status']??'')!=='published'||!empty($row['merged_into_vod_id']))continue;$videos[]=ApiV1VideoDto::summary($row,$locale)->toArray();}
        return ['slug'=>$slug,'name'=>(string)$person['actor_name'],'aliases'=>self::csv($person['actor_alias']??''),'photo'=>(string)($person['actor_pic']??''),'biography'=>(string)($person['actor_content']??''),'area'=>(string)($person['actor_area']??''),'videos'=>$videos];
    }

    private static function csv($value){return array_values(array_filter(array_map('trim',preg_split('/[,，]/u',(string)$value)),'strlen'));}
    private static function csvContains($value,$name){foreach(self::csv($value) as $item)if($item===(string)$name)return true;return false;}
    private static function actorId($slug){$slug=strtoupper(trim((string)$slug));$alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';if(!preg_match('/^[A-Z0-9]{6}$/',$slug))return 0;$value=0;for($i=0;$i<6;$i++){$position=strpos($alphabet,$slug[$i]);if($position===false)return 0;$value=$value*32+$position;}$decoded=(($value-32452843)*44542999)%1073741824;if($decoded<0)$decoded+=1073741824;return (int)$decoded;}
}
