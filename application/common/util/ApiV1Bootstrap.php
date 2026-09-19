<?php
namespace app\common\util;

class ApiV1Bootstrap
{
    public static function resolve(array $server)
    {
        $path = self::requestPath($server);
        if ($path === null) { return null; }
        $path = $path !== '/api/v1/' ? rtrim($path, '/') : '/api/v1';
        if ($path !== '/api/v1' && strpos($path, '/api/v1/') !== 0) { return null; }

        $target = null;
        if ($path === '/api/v1') { $target = '/v1.index/index'; }
        elseif ($path === '/api/v1/home') { $target = '/v1.catalog/home'; }
        elseif ($path === '/api/v1/videos') { $target = '/v1.catalog/videos'; }
        elseif ($path === '/api/v1/search') { $target = '/v1.catalog/search'; }
        elseif ($path === '/api/v1/taxonomies') { $target = '/v1.catalog/taxonomies'; }
        elseif (preg_match('#\A/api/v1/videos/([A-Za-z0-9]{6})/episodes\z#D', $path, $matches)) { $target = '/v1.catalog/episodes/public_id/'.$matches[1]; }
        elseif (preg_match('#\A/api/v1/videos/([A-Za-z0-9]{6})\z#D', $path, $matches)) { $target = '/v1.catalog/detail/public_id/'.$matches[1]; }

        if ($target === null) { return array('module'=>'api','path_info'=>'/v1.index/notFound'); }
        $method = isset($server['REQUEST_METHOD']) ? strtoupper((string) $server['REQUEST_METHOD']) : 'GET';
        return array('module'=>'api','path_info'=>$method === 'GET' ? $target : '/v1.index/methodNotAllowed');
    }

    private static function requestPath(array $server)
    {
        if (isset($server['PATH_INFO']) && is_string($server['PATH_INFO']) && $server['PATH_INFO'] !== '') {
            $path = parse_url($server['PATH_INFO'], PHP_URL_PATH);
        } elseif (isset($server['REQUEST_URI']) && is_string($server['REQUEST_URI'])) {
            $path = parse_url($server['REQUEST_URI'], PHP_URL_PATH);
            if (isset($server['SCRIPT_NAME']) && is_string($server['SCRIPT_NAME'])) {
                $script = $server['SCRIPT_NAME'];
                if ($script !== '' && strpos($path, $script) === 0) { $path = substr($path, strlen($script)); }
                else {
                    $directory = rtrim(str_replace('\\', '/', dirname($script)), '/.');
                    if ($directory !== '' && strpos($path, $directory.'/') === 0) { $path = substr($path, strlen($directory)); }
                }
            }
        } else { return null; }
        if (!is_string($path) || $path === '') { return '/'; }
        return '/'.ltrim($path, '/');
    }
}
