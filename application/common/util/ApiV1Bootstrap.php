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
        $allowed = array('GET');
        if ($path === '/api/v1') { $target = '/v1.index/index'; }
        elseif ($path === '/api/v1/home') { $target = '/v1.catalog/home'; }
        elseif ($path === '/api/v1/videos') { $target = '/v1.catalog/videos'; }
        elseif ($path === '/api/v1/search') { $target = '/v1.catalog/search'; }
        elseif ($path === '/api/v1/taxonomies') { $target = '/v1.catalog/taxonomies'; }
        elseif ($path === '/api/v1/auth/login') { $target = '/v1.auth/login'; $allowed = array('POST'); }
        elseif ($path === '/api/v1/auth/refresh') { $target = '/v1.auth/refresh'; $allowed = array('POST'); }
        elseif ($path === '/api/v1/auth/logout') { $target = '/v1.auth/logout'; $allowed = array('POST'); }
        elseif ($path === '/api/v1/auth/sessions') { $target = '/v1.auth/sessions'; }
        elseif ($path === '/api/v1/me/favorites') { $target = '/v1.activity/favorites'; }
        elseif ($path === '/api/v1/me/history') { $target = '/v1.activity/history'; }
        elseif ($path === '/api/v1/me/activity/merge') { $target = '/v1.activity/merge'; $allowed = array('POST'); }
        elseif (preg_match('#\\A/api/v1/me/favorites/([A-Za-z0-9]{6})\\z#D', $path, $matches)) {
            $target = '/v1.activity/'.((isset($server['REQUEST_METHOD']) && strtoupper((string)$server['REQUEST_METHOD']) === 'DELETE') ? 'unfavorite' : 'favorite').'/public_id/'.$matches[1];
            $allowed = array('PUT','DELETE');
        }
        elseif (preg_match('#\\A/api/v1/me/progress/([A-Za-z0-9]{6})\\z#D', $path, $matches)) {
            $method = isset($server['REQUEST_METHOD']) ? strtoupper((string)$server['REQUEST_METHOD']) : 'GET';
            $actions = array('GET'=>'progress','PUT'=>'saveProgress','DELETE'=>'deleteProgress');
            $target = '/v1.activity/'.(isset($actions[$method]) ? $actions[$method] : 'progress').'/public_id/'.$matches[1];
            $allowed = array('GET','PUT','DELETE');
        }
        elseif (preg_match('#\A/api/v1/auth/sessions/([a-f0-9]{32})\z#D', $path, $matches)) {
            $target = '/v1.auth/revoke/session_id/'.$matches[1]; $allowed = array('DELETE');
        }
        elseif (preg_match('#\A/api/v1/videos/([A-Za-z0-9]{6})/playback/(s[1-9][0-9]{0,2})/(s[1-9][0-9]{0,2}e[1-9][0-9]{0,4})\z#D', $path, $matches)) { $target = '/v1.playback/resolve/public_id/'.$matches[1].'/source_id/'.$matches[2].'/episode_id/'.$matches[3]; }
        elseif (preg_match('#\A/api/v1/videos/([A-Za-z0-9]{6})/episodes\z#D', $path, $matches)) { $target = '/v1.catalog/episodes/public_id/'.$matches[1]; }
        elseif (preg_match('#\A/api/v1/videos/([A-Za-z0-9]{6})\z#D', $path, $matches)) { $target = '/v1.catalog/detail/public_id/'.$matches[1]; }

        if ($target === null) { return array('module'=>'api','path_info'=>'/v1.index/notFound'); }
        $method = isset($server['REQUEST_METHOD']) ? strtoupper((string)$server['REQUEST_METHOD']) : 'GET';
        return array('module'=>'api','path_info'=>in_array($method, $allowed, true) ? $target : '/v1.index/methodNotAllowed');
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
