<?php
namespace app\common\util;

use InvalidArgumentException;

class ApiV1AccessToken
{
    const AUDIENCE = 'maccms-api-v1';

    public static function issue($userId, $sessionId, $secret, $ttl = 900, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        self::assertInputs($userId, $sessionId, $secret, $ttl);
        $payload = array(
            'iss' => 'maccms',
            'aud' => self::AUDIENCE,
            'sub' => (string) (int) $userId,
            'sid' => (string) $sessionId,
            'jti' => bin2hex(random_bytes(16)),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + (int) $ttl,
        );
        $head = self::b64(json_encode(array('typ'=>'JWT','alg'=>'HS256'), JSON_UNESCAPED_SLASHES));
        $body = self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES));
        return $head.'.'.$body.'.'.self::b64(hash_hmac('sha256', $head.'.'.$body, $secret, true));
    }

    public static function verify($token, $secret, $now = null)
    {
        if (!is_string($token) || strlen($secret) < 32) { return null; }
        $parts = explode('.', $token);
        if (count($parts) !== 3) { return null; }
        list($head, $body, $signature) = $parts;
        $header = json_decode(self::unb64($head), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256' || ($header['typ'] ?? '') !== 'JWT') { return null; }
        $expected = self::b64(hash_hmac('sha256', $head.'.'.$body, $secret, true));
        if (!hash_equals($expected, $signature)) { return null; }
        $claims = json_decode(self::unb64($body), true);
        $now = $now === null ? time() : (int) $now;
        if (!is_array($claims)
            || ($claims['iss'] ?? '') !== 'maccms'
            || ($claims['aud'] ?? '') !== self::AUDIENCE
            || !ctype_digit((string) ($claims['sub'] ?? ''))
            || (int) $claims['sub'] < 1
            || preg_match('/\A[a-f0-9]{32}\z/D', (string) ($claims['sid'] ?? '')) !== 1
            || empty($claims['jti'])
            || (int) ($claims['nbf'] ?? PHP_INT_MAX) > $now
            || (int) ($claims['exp'] ?? 0) <= $now
        ) { return null; }
        return $claims;
    }

    public static function bearer($authorization)
    {
        return is_string($authorization) && preg_match('/\ABearer\s+(\S+)\z/iD', trim($authorization), $m) === 1 ? $m[1] : '';
    }

    private static function assertInputs($userId, $sessionId, $secret, $ttl)
    {
        if ((int)$userId < 1 || preg_match('/\A[a-f0-9]{32}\z/D', (string)$sessionId) !== 1
            || strlen((string)$secret) < 32 || (int)$ttl < 300 || (int)$ttl > 3600) {
            throw new InvalidArgumentException('token_configuration');
        }
    }

    private static function b64($value) { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
    private static function unb64($value)
    {
        if (!is_string($value) || preg_match('/\A[A-Za-z0-9_-]+\z/D', $value) !== 1) { return ''; }
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode($value, true);
        return $decoded === false ? '' : $decoded;
    }
}
