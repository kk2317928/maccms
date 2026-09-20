<?php
namespace app\common\util;

final class ApiV1RateLimiter
{
    private $directory;

    public function __construct($directory)
    {
        $this->directory=rtrim((string)$directory,DIRECTORY_SEPARATOR);
    }

    public function consume($bucket,$client,$limit,$window,$now=null)
    {
        $limit=max(1,(int)$limit);
        $window=max(1,(int)$window);
        $now=$now===null?time():(int)$now;
        if (!is_dir($this->directory) && !@mkdir($this->directory,0755,true) && !is_dir($this->directory)) {
            return array('allowed'=>false,'retry_after'=>$window,'remaining'=>0,'unavailable'=>true);
        }
        $key=hash('sha256',(string)$bucket."\0".(string)$client);
        $path=$this->directory.DIRECTORY_SEPARATOR.$key.'.json';
        $fp=@fopen($path,'c+');
        if ($fp===false || !flock($fp,LOCK_EX)) {
            if (is_resource($fp)) fclose($fp);
            return array('allowed'=>false,'retry_after'=>$window,'remaining'=>0,'unavailable'=>true);
        }
        rewind($fp);
        $hits=json_decode((string)stream_get_contents($fp),true);
        if (!is_array($hits)) $hits=array();
        $hits=array_values(array_filter($hits,function($hit)use($now,$window){
            return is_int($hit) || ctype_digit((string)$hit)
                ? $now-(int)$hit<$window && (int)$hit<=$now
                : false;
        }));
        if (count($hits)>=$limit) {
            $retry=max(1,$window-($now-(int)min($hits)));
            flock($fp,LOCK_UN); fclose($fp);
            return array('allowed'=>false,'retry_after'=>$retry,'remaining'=>0,'unavailable'=>false);
        }
        $hits[]=$now;
        ftruncate($fp,0); rewind($fp);
        $written=fwrite($fp,json_encode($hits))!==false;
        fflush($fp); flock($fp,LOCK_UN); fclose($fp);
        if (!$written) return array('allowed'=>false,'retry_after'=>$window,'remaining'=>0,'unavailable'=>true);
        return array('allowed'=>true,'retry_after'=>0,'remaining'=>max(0,$limit-count($hits)),'unavailable'=>false);
    }
}
