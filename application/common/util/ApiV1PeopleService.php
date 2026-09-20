<?php
namespace app\common\util;

use think\Db;

final class ApiV1PeopleService
{
    private $loadPeople;
    private $loadVideos;

    public function __construct(callable $loadPeople=null,callable $loadVideos=null)
    {
        $this->loadPeople=$loadPeople?:static function(){return Db::name('actor')->where('actor_status',1)->field('actor_name,actor_alias,actor_pic,actor_content,actor_area,actor_sex,actor_status')->select();};
        $this->loadVideos=$loadVideos?:static function($name){
            $rows=Db::name('vod')->alias('v')->join(config('database.prefix').'vod_ext e','e.vod_id=v.vod_id')->where(['v.vod_status'=>1,'e.workflow_status'=>'published','e.merged_into_vod_id'=>null])->field('v.vod_name,v.vod_actor,v.vod_director,v.vod_pic,v.vod_year,v.vod_remarks,v.vod_score,e.public_id,e.title_tw,e.title_cn,e.title_en,e.original_title,e.poster_s3,e.published_at,e.workflow_status,e.merged_into_vod_id,v.vod_status')->select();
            return array_values(array_filter($rows,static function($row)use($name){return self::csvContains($row['vod_actor']??'',$name)||self::csvContains($row['vod_director']??'',$name);}));
        };
    }

    public static function slug($name)
    {
        $name=preg_replace('/\s+/u',' ',trim((string)$name));
        $normalized=function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);
        $bytes=hash('sha256','person:'.$normalized,true);$alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';$slug='';
        for($i=0;$i<6;$i++)$slug.=$alphabet[ord($bytes[$i])&31];
        return $slug;
    }

    public function detail($slug,ApiV1Locale $locale)
    {
        $slug=strtoupper(trim((string)$slug));if(!preg_match('/^[A-Z0-9]{6}$/',$slug))return null;
        $matches=[];foreach((array)call_user_func($this->loadPeople) as $row){if((int)($row['actor_status']??0)===1 && self::slug($row['actor_name']??'')===$slug)$matches[]=$row;}
        if(count($matches)!==1)return null;$person=$matches[0];$videos=[];
        foreach((array)call_user_func($this->loadVideos,(string)$person['actor_name']) as $row){if((int)($row['vod_status']??0)!==1||(string)($row['workflow_status']??'')!=='published'||!empty($row['merged_into_vod_id']))continue;$videos[]=ApiV1VideoDto::summary($row,$locale)->toArray();}
        return ['slug'=>$slug,'name'=>(string)$person['actor_name'],'aliases'=>self::csv($person['actor_alias']??''),'photo'=>(string)($person['actor_pic']??''),'biography'=>(string)($person['actor_content']??''),'area'=>(string)($person['actor_area']??''),'videos'=>$videos];
    }

    private static function csv($value){return array_values(array_filter(array_map('trim',preg_split('/[,，]/u',(string)$value)),'strlen'));}
    private static function csvContains($value,$name){foreach(self::csv($value) as $item)if($item===(string)$name)return true;return false;}
}
