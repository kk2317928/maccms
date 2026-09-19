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
        $query=$this->publishedUlogs($userId,4)->where('u.ulog_sid','>',0)->where('u.ulog_nid','>',0)->field(ApiV1CatalogRepository::SUMMARY_FIELDS.',u.ulog_sid,u.ulog_nid,u.ulog_point,u.ulog_duration,u.ulog_time');
        $total=(int)(clone $query)->count();
        $rows=$query->order('u.ulog_time desc,u.ulog_id desc')->limit($pagination->offset(),$pagination->meta(0)['per_page'])->select();
        $items=array();
        foreach($rows as $row) {
            $video=ApiV1VideoDto::summary($row,$locale)->toArray();
            $items[]=ApiV1ActivityDto::history(array(
                'public_id'=>$video['public_id'],'title'=>$video['title'],'poster'=>$video['poster'],
                'source_id'=>'s'.max(1,(int)$row['ulog_sid']),'episode_id'=>'s'.max(1,(int)$row['ulog_sid']).'e'.max(1,(int)$row['ulog_nid']),
                'position_seconds'=>(int)$row['ulog_point'],'duration_seconds'=>(int)$row['ulog_duration'],'updated_at'=>(int)$row['ulog_time'],
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
        $this->withUserLock($userId,function() use($where,$video,$time) {
            $existing=Db::name('ulog')->where($where)->find();
            if (!$existing) Db::name('ulog')->insert($where+array('ulog_rid'=>(int)$video['vod_id'],'ulog_sid'=>0,'ulog_nid'=>0,'ulog_time'=>$time));
        });
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
        return $row && (int)$row['ulog_sid']>0 && (int)$row['ulog_nid']>0 ? $this->progressDto($video,$row) : array();
    }

    public function saveProgress($userId,$publicId,array $input,$clientUpdatedAt=null)
    {
        $video=$this->video($publicId);
        if ($video===null) return null;
        list($sid,$nid)=$this->episodeKey($input);
        $this->assertEpisodeExists($video,$sid,$nid);
        $position=$this->integer($input,'position_seconds',4294967295);
        $duration=$this->integer($input,'duration_seconds',4294967295);
        if ($duration>0 && $position>$duration) throw new InvalidArgumentException('position_seconds');
        $where=$this->ulogWhere($userId,4,$video['vod_id'])+array('ulog_sid'=>$sid,'ulog_nid'=>$nid);
        $time=$this->safeTime($clientUpdatedAt);
        $data=array('ulog_point'=>$position,'ulog_duration'=>$duration,'ulog_time'=>$time);
        $this->withUserLock($userId,function() use($where,$data) {
            $existing=Db::name('ulog')->where($where)->find();
            if ($existing) Db::name('ulog')->where('ulog_id',(int)$existing['ulog_id'])->update($data);
            else Db::name('ulog')->insert($where+$data);
        });
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
        return $this->withUserLock($userId,function() use($userId,$payload) {
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
                $this->assertEpisodeExists($video,$sid,$nid);
                $where=$this->ulogWhere($userId,4,$video['vod_id'])+array('ulog_sid'=>$sid,'ulog_nid'=>$nid);
                $existing=Db::name('ulog')->where($where)->lock(true)->find();
                $client_updated_at=$this->safeTime($item['updated_at']);
                if (!$existing || $client_updated_at>(int)$existing['ulog_time']) {
                    $data=array('ulog_point'=>(int)$item['position_seconds'],'ulog_duration'=>(int)$item['duration_seconds'],'ulog_time'=>$client_updated_at);
                    if ($existing) Db::name('ulog')->where('ulog_id',(int)$existing['ulog_id'])->update($data);
                    else Db::name('ulog')->insert($where+$data);
                    $merged['progress']++;
                }
            }
            return $merged;
            });
        });
    }

    private function video($publicId)
    {
        $resolved=\app\common\model\VodExt::resolvePublicId(strtoupper((string)$publicId));
        if (!$resolved || empty($resolved['canonical_public_id'])) return null;
        $row=Db::name('vod')->alias('v')->join('__VOD_EXT__ e','e.vod_id=v.vod_id')
            ->field('v.vod_id,v.vod_play_from,v.vod_play_url,v.vod_play_server,v.vod_play_note,e.public_id')->where(ApiV1CatalogRepository::PUBLICATION_SQL)
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
        if (preg_match('/\As([1-9][0-9]{0,2})\z/D',$source,$s)!==1 || preg_match('/\As([1-9][0-9]{0,2})e([1-9][0-9]{0,4})\z/D',$episode,$e)!==1 || $s[1]!==$e[1]) {
            throw new InvalidArgumentException('episode_id');
        }
        $sid=(int)$s[1]; $nid=(int)$e[2];
        if ($sid>255 || $nid>65535) throw new InvalidArgumentException('episode_id');
        return array($sid,$nid);
    }

    private function integer(array $input,$key,$maximum)
    {
        $value=isset($input[$key])?$input[$key]:null;
        if (is_int($value) && $value>=0 && $value<=$maximum) return $value;
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D',$value)===1 && (float)$value<=$maximum) return (int)$value;
        throw new InvalidArgumentException($key);
    }

    private function assertEpisodeExists(array $video,$sid,$nid)
    {
        $sources=VodPlaybackCodec::decode((string)$video['vod_play_from'],(string)$video['vod_play_url'],(string)$video['vod_play_server'],(string)$video['vod_play_note']);
        if (!isset($sources[$sid-1]['episodes'][$nid-1])) throw new InvalidArgumentException('episode_id');
    }

    private function withUserLock($userId,$callback)
    {
        $lockName='api_v1_activity_'.sha1((string)(int)$userId);
        $lock=Db::query('SELECT GET_LOCK(?, 5) AS acquired',array($lockName));
        if (empty($lock) || (int)$lock[0]['acquired']!==1) throw new \RuntimeException('activity_lock');
        try { return $callback(); }
        finally {
            try { Db::query('SELECT RELEASE_LOCK(?)',array($lockName)); }
            catch (\Exception $ignored) {}
        }
    }

    private function safeTime($value)
    {
        $value=$value===null?time():(int)$value;
        return max(1,min($value,time()));
    }

    private function progressDto(array $video,array $row)
    {
        $sid=(int)$row['ulog_sid']; $nid=(int)$row['ulog_nid'];
        if ($sid<1 || $nid<1) return array();
        return ApiV1ActivityDto::progress(array(
            'public_id'=>$video['public_id'],'source_id'=>'s'.$sid,'episode_id'=>'s'.$sid.'e'.$nid,
            'position_seconds'=>(int)$row['ulog_point'],'duration_seconds'=>(int)$row['ulog_duration'],'updated_at'=>(int)$row['ulog_time'],
        ))->toArray();
    }
}
