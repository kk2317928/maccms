<?php
namespace app\common\util;

use app\common\model\VodExt;
use InvalidArgumentException;
use think\Db;

final class ApiV1PlaybackService
{
    public function canonicalResource($publicId)
    {
        $resolved=VodExt::resolvePublicId(strtoupper((string)$publicId));
        if (!$resolved || empty($resolved['canonical_public_id'])) return array('status'=>'not_found');
        $published=Db::name('vod')->alias('v')->join('__VOD_EXT__ e','e.vod_id=v.vod_id')
            ->where(ApiV1CatalogRepository::PUBLICATION_SQL)->where('e.public_id',$resolved['canonical_public_id'])->count()>0;
        if (!$published) return array('status'=>'not_found');
        return !empty($resolved['is_alias'])
            ?array('status'=>'redirect','canonical_public_id'=>$resolved['canonical_public_id'])
            :array('status'=>'ok','canonical_public_id'=>$resolved['canonical_public_id']);
    }

    public function resolve($publicId,$sourceId,$episodeId,array $query=array())
    {
        $state=$this->canonicalResource($publicId);
        if ($state['status']!=='ok') return null;
        list($sid,$nid)=$this->identifiers($sourceId,$episodeId);
        $row=Db::name('vod')->alias('v')->join('__VOD_EXT__ e','e.vod_id=v.vod_id')
            ->field('e.public_id,v.vod_play_from,v.vod_play_url,v.vod_play_server,v.vod_play_note')
            ->where(ApiV1CatalogRepository::PUBLICATION_SQL)->where('e.public_id',$state['canonical_public_id'])->find();
        if (!$row) return null;
        $sources=VodPlaybackCodec::decode((string)$row['vod_play_from'],(string)$row['vod_play_url'],(string)$row['vod_play_server'],(string)$row['vod_play_note']);
        if (!isset($sources[$sid-1]['episodes'][$nid-1])) throw new InvalidArgumentException('episode_id');
        $source=$sources[$sid-1]; $episode=$source['episodes'][$nid-1];
        if (trim((string)$episode['url'])==='') throw new InvalidArgumentException('playback_url');
        $settings=$this->settings();
        (new ApiV1PlaybackPolicy($settings['sources']))->authorize((string)$source['source'],(string)$episode['url']);
        $expiresAt=0;
        if ($settings['signing_enabled']) {
            $signer=new ApiV1PlaybackSigner($settings['secret'],$settings['ttl']);
            if (!$signer->verify($row['public_id'],$sourceId,$episodeId,$query['expires_at']??0,$query['signature']??'')) {
                throw new InvalidArgumentException('signature');
            }
            $expiresAt=(int)$query['expires_at'];
        }
        return ApiV1PlaybackDto::make(array(
            'public_id'=>$row['public_id'],'source_id'=>$sourceId,'episode_id'=>$episodeId,
            'url'=>$episode['url'],'expires_at'=>$expiresAt,
        ));
    }

    private function identifiers($sourceId,$episodeId)
    {
        if (preg_match('/\As([1-9][0-9]{0,2})\z/D',(string)$sourceId,$s)!==1
            || preg_match('/\As([1-9][0-9]{0,2})e([1-9][0-9]{0,4})\z/D',(string)$episodeId,$e)!==1
            || $s[1]!==$e[1] || (int)$s[1]>255 || (int)$e[2]>65535) throw new InvalidArgumentException('episode_id');
        return array((int)$s[1],(int)$e[2]);
    }

    private function settings()
    {
        $app=isset($GLOBALS['config']['app'])&&is_array($GLOBALS['config']['app'])?$GLOBALS['config']['app']:array();
        $sources=isset($app['api_v1_playback_sources'])&&is_array($app['api_v1_playback_sources'])?$app['api_v1_playback_sources']:array();
        $signing=isset($app['api_v1_playback_signing'])&&is_array($app['api_v1_playback_signing'])?$app['api_v1_playback_signing']:array();
        $secret=trim((string)($signing['secret']??getenv('MACCMS_API_V1_PLAYBACK_SECRET')));
        return array(
            'sources'=>$sources,'signing_enabled'=>!empty($signing['enabled']),
            'secret'=>$secret,'ttl'=>(int)($signing['ttl']??300),
        );
    }
}
