<?php
namespace app\common\util;

use RuntimeException;

final class ApiV1OpenApi
{
    public static function raw($path=null)
    {
        $path=self::path($path);
        $raw=file_get_contents($path);
        if (!is_string($raw) || $raw==='' || strlen($raw)>1048576) throw new RuntimeException('openapi_size');
        return $raw;
    }

    public static function document($path=null)
    {
        $document=json_decode(self::raw($path),true);
        if (!is_array($document) || ($document['openapi']??null)!=='3.0.3' || !isset($document['paths'],$document['components'])) {
            throw new RuntimeException('openapi_invalid');
        }
        return $document;
    }

    private static function path($path)
    {
        if ($path===null) {
            if (!defined('ROOT_PATH')) throw new RuntimeException('openapi_root');
            $path=ROOT_PATH.'docs/api/v1/openapi.json';
        }
        $path=(string)$path;
        if (!is_file($path)) throw new RuntimeException('openapi_missing');
        return $path;
    }
}
