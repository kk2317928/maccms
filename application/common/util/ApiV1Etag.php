<?php
namespace app\common\util;

final class ApiV1Etag
{
    public static function make($payload,$generation=0)
    {
        $normalized=self::normalize($payload);
        return '"'.hash('sha256',(string)(int)$generation."\n".json_encode($normalized,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)).'"';
    }

    public static function matches($ifNoneMatch,$etag)
    {
        $etag=trim((string)$etag);
        foreach(explode(',',(string)$ifNoneMatch) as $candidate) {
            $candidate=trim($candidate);
            if (stripos($candidate,'W/')===0) $candidate=trim(substr($candidate,2));
            if ($candidate==='*' || hash_equals($etag,$candidate)) return true;
        }
        return false;
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
