<?php
namespace app\common\util;

use InvalidArgumentException;

final class ApiV1PlaybackSigner
{
    private $secret;
    private $ttl;

    public function __construct($secret,$ttl=300)
    {
        $this->secret=(string)$secret;
        $this->ttl=(int)$ttl;
        if (strlen($this->secret)<32 || $this->ttl<30 || $this->ttl>900) throw new InvalidArgumentException('playback_signing');
    }

    public function sign($publicId,$sourceId,$episodeId,$now=null)
    {
        $expires=($now===null?time():(int)$now)+$this->ttl;
        return array('expires_at'=>$expires,'signature'=>$this->digest($publicId,$sourceId,$episodeId,$expires));
    }

    public function verify($publicId,$sourceId,$episodeId,$expiresAt,$signature,$now=null)
    {
        $now=$now===null?time():(int)$now; $expiresAt=(int)$expiresAt;
        if ($expiresAt<=$now || $expiresAt>$now+900 || !is_string($signature) || preg_match('/\A[a-f0-9]{64}\z/D',$signature)!==1) return false;
        return hash_equals($this->digest($publicId,$sourceId,$episodeId,$expiresAt),$signature);
    }

    private function digest($publicId,$sourceId,$episodeId,$expiresAt)
    {
        return hash_hmac('sha256',strtoupper((string)$publicId)."\n".(string)$sourceId."\n".(string)$episodeId."\n".(int)$expiresAt,$this->secret);
    }
}
