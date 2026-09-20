<?php
namespace app\common\util;

use RuntimeException;

final class ApiV1OpenApi
{
    public static function document($path=null)
    {
        if ($path===null) {
            if (!defined('ROOT_PATH')) throw new RuntimeException('openapi_root');
            $path=ROOT_PATH.'docs/api/v1/openapi.json';
        }
        $path=(string)$path;
        if (!is_file($path)) throw new RuntimeException('openapi_missing');
        $raw=file_get_contents($path);
        if (!is_string($raw) || $raw==='' || strlen($raw)>1048576) throw new RuntimeException('openapi_size');
        $document=json_decode($raw,true);
        if (!is_array($document) || ($document['openapi']??null)!=='3.0.3' || !isset($document['paths'],$document['components'])) {
            throw new RuntimeException('openapi_invalid');
        }
        return $document;
    }
}
