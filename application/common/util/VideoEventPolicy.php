<?php
namespace app\common\util;

use InvalidArgumentException;

final class VideoEventPolicy
{
    private $retentionDays; private $perMinute; private $batchMax;
    public function __construct($retentionDays=90,$perMinute=120,$batchMax=20)
    {
        foreach([$retentionDays,$perMinute,$batchMax] as $v)if((int)$v<1)throw new InvalidArgumentException('Policy limits must be positive.');
        $this->retentionDays=(int)$retentionDays;$this->perMinute=(int)$perMinute;$this->batchMax=(int)$batchMax;
    }
    public function rawCutoff($now):int{return (int)$now-$this->retentionDays*86400;}
    public function acceptsOccurredAt($occurred,$now):bool{return (int)$occurred>=(int)$now-86400 && (int)$occurred<=(int)$now+300;}
    public function allowBatch($count):bool{return (int)$count>=1 && (int)$count<=$this->batchMax;}
    public function allowActorRate($acceptedInMinute,$incoming=1):bool{return (int)$acceptedInMinute>=0 && (int)$incoming>=1 && (int)$acceptedInMinute+(int)$incoming<=$this->perMinute;}
    public function purgeSql($prefix,$now):string
    {
        if(!preg_match('/^[A-Za-z0-9_]+$/',(string)$prefix))throw new InvalidArgumentException('Invalid table prefix.');
        return 'DELETE FROM `'.$prefix.'api_video_event` WHERE `received_at` < '.$this->rawCutoff($now).' LIMIT 1000';
    }
}
