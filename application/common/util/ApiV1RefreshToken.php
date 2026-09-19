<?php
namespace app\common\util;

use InvalidArgumentException;

class ApiV1RefreshToken
{
    public static function generate()
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function isValid($token)
    {
        return is_string($token) && preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token) === 1;
    }

    public static function digest($token)
    {
        if (!self::isValid($token)) { throw new InvalidArgumentException('refresh_token'); }
        return hash('sha256', $token);
    }
}
