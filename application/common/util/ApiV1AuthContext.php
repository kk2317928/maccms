<?php
namespace app\common\util;

use think\Request;

final class ApiV1AuthContext
{
    public static function claims(Request $request)
    {
        $token = ApiV1AccessToken::bearer($request->header('Authorization'));
        $claims = $token === '' ? null : ApiV1AccessToken::verify($token, self::secret());
        if ($claims === null || !(new ApiV1SessionService())->isActive((int)$claims['sub'], (string)$claims['sid'])) {
            return null;
        }
        return $claims;
    }

    public static function userId(Request $request)
    {
        $claims = self::claims($request);
        return $claims === null ? null : (int)$claims['sub'];
    }

    public static function secret()
    {
        $app = isset($GLOBALS['config']['app']) && is_array($GLOBALS['config']['app']) ? $GLOBALS['config']['app'] : array();
        $secret = trim((string)(isset($app['api_v1_jwt_secret']) ? $app['api_v1_jwt_secret'] : getenv('MACCMS_API_V1_JWT_SECRET')));
        return strlen($secret) >= 32 ? $secret : '';
    }
}
