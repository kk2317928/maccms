<?php
namespace app\common\util;

final class ApiV1Etag
{
    public static function make($payload,$generation=0)
    {
        $normalized=self::normalize($payload);
        return 'W/"'.hash('sha256',(string)(int)$generation."\n".json_encode($normalized,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)).'"';
    }

    public static function matches($ifNoneMatch,$etag)
    {
        $etag=self::opaqueTag($etag);
        foreach(explode(',',(string)$ifNoneMatch) as $candidate) {
            $candidate=trim($candidate);
            if ($candidate==='*') return true;
            if ($etag!=='' && hash_equals($etag,self::opaqueTag($candidate))) return true;
        }
        return false;
    }

    private static function opaqueTag($value)
    {
        $value=trim((string)$value);
        if (stripos($value,'W/')===0) $value=trim(substr($value,2));
        return preg_match('/\\A"[a-f0-9]{64}"\\z/D',$value)===1?$value:'';
    }

    private static function normalize($value)
    {
        if (!is_array($value)) return $value;
        $keys=array_keys($value);
        $sequential=$keys===range(0,count($value)-1);
        if (!$sequential) ksort($value,SORT_STRING);
        foreach($value as $key=>$item) $value[$key]=self::normalize($item);
        return $value;
    }
}
