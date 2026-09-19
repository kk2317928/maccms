<?php
namespace app\common\util;

use think\Db;

class ApiV1SessionService
{
    const REFRESH_TTL = 2592000;

    public function create($userId, $deviceName, $userAgent, $ip, $now = null)
    {
        $now = $now === null ? time() : (int)$now;
        $token = ApiV1RefreshToken::generate();
        $sessionId = bin2hex(random_bytes(16));
        $familyId = bin2hex(random_bytes(16));
        Db::name('api_refresh_session')->insert($this->row(
            $userId, $sessionId, $familyId, $token, null, $deviceName, $userAgent, $ip, $now
        ));
        return array('refresh_token'=>$token, 'session_id'=>$sessionId, 'expires_at'=>$now+self::REFRESH_TTL);
    }

    public function rotate($token, $userAgent, $ip, $now = null)
    {
        if (!ApiV1RefreshToken::isValid($token)) { return array('status'=>'invalid'); }
        $now = $now === null ? time() : (int)$now;
        $hash = ApiV1RefreshToken::digest($token);
        return Db::transaction(function () use ($hash, $userAgent, $ip, $now) {
            $current = Db::name('api_refresh_session')->where('token_hash', $hash)->lock(true)->find();
            if (empty($current)) { return array('status'=>'invalid'); }
            if (!empty($current['consumed_at'])) {
                Db::name('api_refresh_session')->where('family_id', $current['family_id'])
                    ->whereNull('revoked_at')->update(array('revoked_at'=>$now,'revoke_reason'=>'replay'));
                return array('status'=>'replayed');
            }
            if (!empty($current['revoked_at']) || (int)$current['expires_at'] <= $now) {
                return array('status'=>'invalid');
            }
            $next = ApiV1RefreshToken::generate();
            $updated = Db::name('api_refresh_session')->where('refresh_id', $current['refresh_id'])
                ->whereNull('consumed_at')->update(array('consumed_at'=>$now,'last_used_at'=>$now));
            if ($updated !== 1) { return array('status'=>'retry'); }
            Db::name('api_refresh_session')->insert($this->row(
                (int)$current['user_id'], $current['session_id'], $current['family_id'], $next,
                $hash, $current['device_name'], $userAgent, $ip, $now
            ));
            return array(
                'status'=>'ok', 'user_id'=>(int)$current['user_id'], 'session_id'=>$current['session_id'],
                'refresh_token'=>$next, 'expires_at'=>$now+self::REFRESH_TTL
            );
        });
    }

    public function isActive($userId, $sessionId, $now = null)
    {
        $now = $now === null ? time() : (int)$now;
        return Db::name('api_refresh_session')->where(array(
            'user_id'=>(int)$userId, 'session_id'=>(string)$sessionId,
        ))->whereNull('consumed_at')->whereNull('revoked_at')->where('expires_at', '>', $now)->count() > 0;
    }

    public function revokeFamily($userId, $sessionId, $reason = 'logout', $now = null)
    {
        $now = $now === null ? time() : (int)$now;
        return Db::name('api_refresh_session')->where(array('user_id'=>(int)$userId,'session_id'=>(string)$sessionId))
            ->whereNull('revoked_at')->update(array('revoked_at'=>$now,'revoke_reason'=>(string)$reason));
    }

    public function listForUser($userId)
    {
        $rows = Db::name('api_refresh_session')->field('session_id,device_name,created_at,last_used_at,expires_at,revoked_at')
            ->where('user_id', (int)$userId)->order('created_at desc,refresh_id desc')->select();
        $sessions = array();
        foreach ($rows as $row) {
            if (!isset($sessions[$row['session_id']])) {
                $sessions[$row['session_id']] = array(
                    'session_id'=>$row['session_id'], 'device_name'=>$row['device_name'],
                    'created_at'=>(int)$row['created_at'], 'last_used_at'=>(int)$row['last_used_at'],
                    'expires_at'=>(int)$row['expires_at'], 'revoked'=>(bool)$row['revoked_at'],
                );
            } else {
                $sessions[$row['session_id']]['created_at'] = min(
                    $sessions[$row['session_id']]['created_at'], (int)$row['created_at']
                );
            }
        }
        return array_values($sessions);
    }

    private function fingerprint($domain, $value)
    {
        $app = isset($GLOBALS['config']['app']) && is_array($GLOBALS['config']['app']) ? $GLOBALS['config']['app'] : array();
        $secret = trim((string)($app['api_v1_jwt_secret'] ?? getenv('MACCMS_API_V1_JWT_SECRET')));
        return hash_hmac('sha256', (string)$domain."\0".(string)$value, $secret);
    }

    private function row($userId, $sessionId, $familyId, $token, $parentHash, $deviceName, $userAgent, $ip, $now)
    {
        $deviceName = trim((string)$deviceName);
        if (function_exists('mb_substr')) { $deviceName = mb_substr($deviceName, 0, 80, 'UTF-8'); }
        else { $deviceName = substr($deviceName, 0, 80); }
        return array(
            'session_id'=>$sessionId, 'family_id'=>$familyId, 'user_id'=>(int)$userId,
            'token_hash'=>ApiV1RefreshToken::digest($token), 'parent_hash'=>$parentHash,
            'device_name'=>$deviceName, 'user_agent_hash'=>$this->fingerprint('ua', $userAgent),
            'ip_hash'=>$this->fingerprint('ip', $ip), 'created_at'=>$now, 'last_used_at'=>$now,
            'expires_at'=>$now+self::REFRESH_TTL, 'consumed_at'=>null, 'revoked_at'=>null, 'revoke_reason'=>'',
        );
    }
}
