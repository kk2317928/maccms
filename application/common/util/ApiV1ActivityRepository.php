<?php
namespace app\common\util;

use InvalidArgumentException;
use think\Db;

final class ApiV1ActivityRepository
{
    const MID = 1;

    public function favorites($userId, ApiV1Pagination $pagination, ApiV1Locale $locale)
    {
        $query=$this->publishedUlogs($userId,2)->field(ApiV1CatalogRepository::SUMMARY_FIELDS.',u.ulog_time');
        $total=(int)(clone $query)->count();
        $rows=$query->order('u.ulog_time desc,u.ulog_id desc')->limit($pagination->offset(),$pagination->meta(0)['per_page'])->select();
        $items=array();
        foreach($rows as $row) {
            $video=ApiV1VideoDto::summary($row,$locale)->toArray();
            $items[]=ApiV1ActivityDto::favorite(array('public_id'=>$video['public_id'],'title'=>$video['title'],'poster'=>$video['poster'],'favorited_at'=>(int)$row['ulog_time']))->toArray();
        }
        return array('items'=>$items,'total'=>$total);
    }

    public function history($userId, ApiV1Pagination $pagination, ApiV1Locale $locale)
    {
        $query=$this->publishedUlogs($userId,4)->field(ApiV1CatalogRepository::SUMMARY_FIELDS.',u.ulog_sid,u.ulog_nid,u.ulog_point,u.ulog_time');
        $total=(int)(clone $query)->count();
        $rows=$query->order('u.ulog_time desc,u.ulog_id desc')->limit($pagination->offset(),$pagination->meta(0)['per_page'])->select();
        $items=array();
        foreach($rows as $row) {
            $video=ApiV1VideoDto::summary($row,$locale)->toArray();
            $items[]=ApiV1ActivityDto::history(array(
                'public_id'=>$video['public_id'],'title'=>$video['title'],'poster'=>$video['poster'],
                'source_id'=>'s'.max(1,(int)$row['ulog_sid']),'episode_id'=>'s'.max(1,(int)$row['ulog_sid']).'e'.max(1,(int)$row['ulog_nid']),
                'position_seconds'=>(int)$row['ulog_point'],'duration_seconds'=>0,'updated_at'=>(int)$row['ulog_time'],
            ))->toArray();
        }
        return array('items'=>$items,'total'=>$total);
    }

    public function favorite($userId,$publicId,$updatedAt=null)
    {
        $video=$this->video($publicId);
        if ($video===null) return null;
        $where=$this->ulogWhere($userId,2,$video['vod_id']);
        $time=$this->safeTime($updatedAt);
        $existing=Db::name('ulog')->where($where)->find();
        if ($existing) Db::name('ulog')->where('ulog_id',(int)$existing['ulog_id'])->update(array('ulog_time'=>$time));
        else Db::name('ulog')->insert($where+array('ulog_rid'=>(int)$video['vod_id'],'ulog_sid'=>0,'ulog_nid'=>0,'ulog_time'=>$time));
        return (string)$video['public_id'];
    }

    public function unfavorite($userId,$publicId)
    {
        $video=$this->video($publicId);
        if ($video===null) return null;
        Db::name('ulog')->where($this->ulogWhere($userId,2,$video['vod_id']))->delete();
        return (string)$video['public_id'];
    }

    public function progress($userId,$publicId)
    {
        $video=$this->video($publicId);
        if ($video===null) return null;
        $row=Db::name('ulog')->where($this->ulogWhere($userId,4,$video['vod_id']))->order('ulog_time desc,ulog_id desc')->find();
        return $row ? $this->progressDto($video,$row) : array();
    }

    public function saveProgress($userId,$publicId,array $input,$clientUpdatedAt=null)
    {
        $video=$this->video($publicId);
        if ($video===null) return null;
        list($sid,$nid)=$this->episodeKey($input);
        $position=$this->integer($input,'position_seconds');
        $duration=$this->integer($input,'duration_seconds');
        if ($duration>0 && $position>$duration) throw new InvalidArgumentException('position_seconds');
        $where=$this->ulogWhere($userId,4,$video['vod_id'])+array('ulog_sid'=>$sid,'ulog_nid'=>$nid);
        $time=$this->safeTime($clientUpdatedAt);
        $existing=Db::name('ulog')->where($where)->find();
        $data=array('ulog_point'=>$position,'ulog_time'=>$time);
        if ($existing) Db::name('ulog')->where('ulog_id',(int)$existing['ulog_id'])->update($data);
        else Db::name('ulog')->insert($where+$data);
        return $this->progressDto($video,$where+$data);
    }

    public function deleteProgress($userId,$publicId)
    {
        $video=$this->video($publicId);
        if ($video===null) return null;
        Db::name('ulog')->where($this->ulogWhere($userId,4,$video['vod_id']))->delete();
        return (string)$video['public_id'];
    }

    public function merge($userId,array $payload)
    {
        return Db::transaction(function() use($userId,$payload) {
            $merged=array('favorites'=>0,'progress'=>0);
            foreach($payload['favorites'] as $item) {
                $video=$this->video($item['public_id']);
                if ($video===null) continue;
                $where=$this->ulogWhere($userId,2,$video['vod_id']);
                if (!Db::name('ulog')->where($where)->find()) {
                    Db::name('ulog')->insert($where+array('ulog_rid'=>(int)$video['vod_id'],'ulog_sid'=>0,'ulog_nid'=>0,'ulog_time'=>$this->safeTime($item['updated_at'])));
                    $merged['favorites']++;
                }
            }
            foreach($payload['progress'] as $item) {
                $video=$this->video($item['public_id']);
                if ($video===null) continue;
                list($sid,$nid)=$this->episodeKey($item);
                $where=$this->ulogWhere($userId,4,$video['vod_id'])+array('ulog_sid'=>$sid,'ulog_nid'=>$nid);
                $existing=Db::name('ulog')->where($where)->lock(true)->find();
                $client_updated_at=$this->safeTime($item['updated_at']);
                if (!$existing || $client_updated_at>(int)$existing['ulog_time']) {
                    $data=array('ulog_point'=>(int)$item['position_seconds'],'ulog_time'=>$client_updated_at);
                    if ($existing) Db::name('ulog')->where('ulog_id',(int)$existing['ulog_id'])->update($data);
                    else Db::name('ulog')->insert($where+$data);
                    $merged['progress']++;
                }
            }
            return $merged;
        });
    }

    private function video($publicId)
    {
        $resolved=\app\common\model\VodExt::resolvePublicId(strtoupper((string)$publicId));
        if (!$resolved || empty($resolved['canonical_public_id'])) return null;
        $row=Db::name('vod')->alias('v')->join('__VOD_EXT__ e','e.vod_id=v.vod_id')
            ->field('v.vod_id,e.public_id')->where(ApiV1CatalogRepository::PUBLICATION_SQL)
            ->where('e.public_id',$resolved['canonical_public_id'])->find();
        return $row ?: null;
    }

    private function publishedUlogs($userId,$type)
    {
        return Db::name('ulog')->alias('u')->join('__VOD__ v','v.vod_id=u.ulog_rid')
            ->join('__VOD_EXT__ e','e.vod_id=v.vod_id')->where(ApiV1CatalogRepository::PUBLICATION_SQL)
            ->where(array('u.user_id'=>(int)$userId,'u.ulog_mid'=>self::MID,'u.ulog_type'=>(int)$type));
    }

    private function ulogWhere($userId,$type,$vodId)
    {
        return array('user_id'=>(int)$userId,'ulog_mid'=>self::MID,'ulog_type'=>(int)$type,'ulog_rid'=>(int)$vodId);
    }

    private function episodeKey(array $input)
    {
        $source=(string)(isset($input['source_id'])?$input['source_id']:'');
        $episode=(string)(isset($input['episode_id'])?$input['episode_id']:'');
        if (preg_match('/\As([1-9][0-9]*)\z/D',$source,$s)!==1 || preg_match('/\As([1-9][0-9]*)e([1-9][0-9]*)\z/D',$episode,$e)!==1 || $s[1]!==$e[1]) {
            throw new InvalidArgumentException('episode_id');
        }
        return array((int)$s[1],(int)$e[2]);
    }

    private function integer(array $input,$key)
    {
        $value=isset($input[$key])?$input[$key]:null;
        if (is_int($value) && $value>=0) return $value;
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)===1) return (int)$value;
        throw new InvalidArgumentException($key);
    }

    private function safeTime($value)
    {
        $value=$value===null?time():(int)$value;
        return max(1,min($value,time()));
    }

    private function progressDto(array $video,array $row)
    {
        $sid=max(1,(int)$row['ulog_sid']); $nid=max(1,(int)$row['ulog_nid']);
        return ApiV1ActivityDto::progress(array(
            'public_id'=>$video['public_id'],'source_id'=>'s'.$sid,'episode_id'=>'s'.$sid.'e'.$nid,
            'position_seconds'=>(int)$row['ulog_point'],'duration_seconds'=>0,'updated_at'=>(int)$row['ulog_time'],
        ))->toArray();
    }
}
