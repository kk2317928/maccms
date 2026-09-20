<?php
namespace app\common\util;

final class ApiV1EndpointPolicy
{
    public static function resolve($method,$path)
    {
        $method=strtoupper((string)$method);
        $path=parse_url((string)$path,PHP_URL_PATH);
        $path=is_string($path)?rtrim($path,'/'):'';
        if ($path==='') $path='/';

        if ($path==='/api/v1/auth/login' || $path==='/api/v1/auth/refresh' || $path==='/api/v1/auth/logout') {
            return self::policy('auth',10,60,array('POST'),false);
        }
        if ($path==='/api/v1/auth/sessions') {
            return self::policy('auth',10,60,array('GET'),false);
        }
        if (preg_match('#\\A/api/v1/auth/sessions/[a-f0-9]{32}\\z#D',$path)===1) {
            return self::policy('auth',10,60,array('DELETE'),false);
        }
        if ($path==='/api/v1/search') {
            return self::policy('search',30,60,array('GET'),$method==='GET');
        }
        if (preg_match('#\\A/api/v1/videos/[A-Za-z0-9]{6}/playback/#D',$path)===1) {
            return self::policy('playback',30,60,array('GET'),false);
        }
        if (strpos($path,'/api/v1/me/')===0) {
            return self::policy('member',60,60,array('GET','POST','PUT','DELETE'),false);
        }
        return self::policy('catalog',120,60,array('GET'),$method==='GET');
    }

    private static function policy($bucket,$limit,$window,array $methods,$cacheable)
    {
        return array(
            'bucket'=>(string)$bucket,
            'limit'=>(int)$limit,
            'window'=>(int)$window,
            'methods'=>$methods,
            'cacheable'=>(bool)$cacheable,
        );
    }
}
