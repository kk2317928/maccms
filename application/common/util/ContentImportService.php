<?php
namespace app\common\util;

use InvalidArgumentException;
use RuntimeException;
use think\Db;
use Throwable;

final class ContentImportService
{
    const MAX_PAYLOAD_BYTES=262144;
    private $lookup;
    private $reserve;
    private $complete;
    private $nativeWrite;
    private $loadExtension;
    private $transaction;

    public function __construct(callable $lookup=null,callable $reserve=null,callable $complete=null,callable $nativeWrite=null,callable $loadExtension=null,callable $transaction=null)
    {
        $this->lookup=$lookup?:function($key){return Db::name('video_import_request')->where('idempotency_key',$key)->find();};
        $this->reserve=$reserve?:function($key,$fingerprint){Db::name('video_import_request')->insert(['idempotency_key'=>$key,'request_fingerprint'=>$fingerprint,'status'=>'processing','created_at'=>time()]);};
        $this->complete=$complete?:function($key,$result){Db::name('video_import_request')->where('idempotency_key',$key)->update(['status'=>'succeeded','response_json'=>json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'completed_at'=>time()]);};
        $this->nativeWrite=$nativeWrite?:function($payload){return model('Vod')->saveData($payload);};
        $this->loadExtension=$loadExtension?:function($vodId){$row=Db::name('vod_ext')->where('vod_id',(int)$vodId)->field('vod_id,public_id,workflow_status')->find();if(!$row)throw new RuntimeException('Video extension was not created.');return $row;};
        $this->transaction=$transaction?:function($callback){Db::startTrans();try{$result=$callback();Db::commit();return $result;}catch(Throwable $e){Db::rollback();throw $e;}};
    }

    public function import(array $payload,$idempotencyKey)
    {
        $key=trim((string)$idempotencyKey);
        if(!preg_match('/^[A-Za-z0-9._:-]{12,191}$/',$key)) throw new InvalidArgumentException('Invalid idempotency key.');
        $encoded=json_encode($this->canonicalize($payload),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if($encoded===false || strlen($encoded)>self::MAX_PAYLOAD_BYTES) throw new InvalidArgumentException('Import payload is too large.');
        $fingerprint=hash('sha256',$encoded);$existing=call_user_func($this->lookup,$key);
        if($existing) return $this->replay($existing,$fingerprint);
        $this->validate($payload);
        return call_user_func($this->transaction,function()use($payload,$key,$fingerprint){
            call_user_func($this->reserve,$key,$fingerprint);
            if(isset($payload['playback_sources'])){
                $payload=$this->mapPlaybackSources($payload);
            }
            $nativePayload=array_merge([
                'vod_id'=>0,'vod_en'=>'','vod_content'=>'','vod_blurb'=>'','vod_play_from'=>[],
                'vod_play_server'=>[],'vod_play_note'=>[],'vod_play_url'=>[],'vod_down_from'=>[],
                'vod_down_server'=>[],'vod_down_note'=>[],'vod_down_url'=>[],'uptime'=>0,'uptag'=>0,
            ],$payload);
            $write=call_user_func($this->nativeWrite,$nativePayload);
            if(!is_array($write) || (int)($write['code']??0)!==1 || (int)($write['vod_id']??0)<=0) throw new RuntimeException('Native video persistence failed.');
            $result=call_user_func($this->loadExtension,(int)$write['vod_id']);
            $result=['vod_id'=>(int)$result['vod_id'],'public_id'=>(string)$result['public_id'],'workflow_status'=>(string)$result['workflow_status']];
            call_user_func($this->complete,$key,$result);return $result;
        });
    }

    private function replay(array $row,$fingerprint)
    {
        if(!hash_equals((string)$row['request_fingerprint'],$fingerprint)) throw new InvalidArgumentException('Idempotency key payload conflict.');
        if((string)($row['status']??'')!=='succeeded') throw new InvalidArgumentException('Import request is still processing.');
        $result=json_decode((string)($row['response_json']??''),true);
        if(!is_array($result)) throw new RuntimeException('Stored import result is invalid.');
        return $result;
    }

    private function validate(array $payload)
    {
        $allowed=['vod_name','vod_en','type_id','vod_sub','vod_year','vod_area','vod_lang','vod_class','vod_actor','vod_director','vod_writer','vod_remarks','vod_blurb','vod_content','vod_pic','vod_play_from','vod_play_server','vod_play_note','vod_play_url','playback_sources'];
        foreach(array_keys($payload) as $field) if(!in_array($field,$allowed,true)) throw new InvalidArgumentException('Import field is not allowed: '.$field);
        if(trim((string)($payload['vod_name']??''))==='' || (int)($payload['type_id']??0)<=0) throw new InvalidArgumentException('vod_name and type_id are required.');
        foreach((array)($payload['vod_play_url']??[]) as $group){foreach(explode('#',(string)$group) as $episode){$parts=explode('$',$episode,2);$url=count($parts)===2?$parts[1]:$parts[0];$scheme=strtolower((string)parse_url(trim($url),PHP_URL_SCHEME));if(!in_array($scheme,['http','https'],true))throw new InvalidArgumentException('Unsafe playback URL.');}}
        foreach((array)($payload['playback_sources']??[]) as $source){if(!is_array($source))throw new InvalidArgumentException('Invalid playback source.');foreach((array)($source['episodes']??[]) as $episode){$url=is_array($episode)?($episode['url']??''):'';$scheme=strtolower((string)parse_url(trim((string)$url),PHP_URL_SCHEME));if(!in_array($scheme,['http','https'],true))throw new InvalidArgumentException('Unsafe playback URL.');}}
    }

    private function mapPlaybackSources(array $payload)
    {
        $from=[];$server=[];$note=[];$urls=[];
        foreach((array)$payload['playback_sources'] as $source){$from[]=(string)($source['source']??'');$server[]=(string)($source['server']??'');$note[]=(string)($source['note']??'');$episodes=[];foreach((array)($source['episodes']??[]) as $episode)$episodes[]=(string)($episode['title']??'').'$'.(string)($episode['url']??'');$urls[]=implode('#',$episodes);}
        unset($payload['playback_sources']);$payload['vod_play_from']=$from;$payload['vod_play_server']=$server;$payload['vod_play_note']=$note;$payload['vod_play_url']=$urls;return $payload;
    }

    private function canonicalize($value)
    {
        if(!is_array($value))return $value;
        if(array_keys($value)!==range(0,count($value)-1))ksort($value,SORT_STRING);
        foreach($value as $key=>$item)$value[$key]=$this->canonicalize($item);
        return $value;
    }
}
