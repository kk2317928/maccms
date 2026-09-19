<?php
namespace app\common\util;

class ApiV1Bootstrap
{
    public static function resolve(array $server)
    {
        $path = self::requestPath($server);
        if ($path === null) {
            return null;
        }

        if ($path === '/api/v1' || $path === '/api/v1/') {
            $method = isset($server['REQUEST_METHOD']) ? strtoupper((string) $server['REQUEST_METHOD']) : 'GET';
            return array(
                'module' => 'api',
                'path_info' => $method === 'GET' ? '/v1.index/index' : '/v1.index/methodNotAllowed',
            );
        }

        if (strpos($path, '/api/v1/') === 0) {
            return array('module' => 'api', 'path_info' => '/v1.index/notFound');
        }

        return null;
    }

    private static function requestPath(array $server)
    {
        if (isset($server['PATH_INFO']) && is_string($server['PATH_INFO']) && $server['PATH_INFO'] !== '') {
            $path = parse_url($server['PATH_INFO'], PHP_URL_PATH);
        } elseif (isset($server['REQUEST_URI']) && is_string($server['REQUEST_URI'])) {
            $path = parse_url($server['REQUEST_URI'], PHP_URL_PATH);
            if (isset($server['SCRIPT_NAME']) && is_string($server['SCRIPT_NAME'])) {
                $script = $server['SCRIPT_NAME'];
                if ($script !== '' && strpos($path, $script) === 0) {
                    $path = substr($path, strlen($script));
                } else {
                    $directory = rtrim(str_replace('\\', '/', dirname($script)), '/.');
                    if ($directory !== '' && strpos($path, $directory . '/') === 0) {
                        $path = substr($path, strlen($directory));
                    }
                }
            }
        } else {
            return null;
        }

        if (!is_string($path) || $path === '') {
            return '/';
        }
        return '/' . ltrim($path, '/');
    }
}
