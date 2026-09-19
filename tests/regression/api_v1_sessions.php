<?php
declare(strict_types=1);

function authFail($message) { fwrite(STDERR, "FAIL: ".$message.PHP_EOL); exit(1); }
function authAssert($condition, $message) { if (!$condition) { authFail($message); } }
function authSame($expected, $actual, $message) { if ($expected !== $actual) { authFail($message); } }

$root = dirname(__DIR__, 2);
foreach (array(
    'application/common/util/ApiV1AccessToken.php',
    'application/common/util/ApiV1RefreshToken.php',
    'application/common/util/ApiV1SessionService.php',
    'application/api/controller/v1/Auth.php',
    'application/data/migrations/20260919000500_api_refresh_sessions.sql',
) as $file) { authAssert(is_file($root.'/'.$file), 'Missing '.$file); }

require_once $root.'/application/common/util/ApiV1AccessToken.php';
require_once $root.'/application/common/util/ApiV1RefreshToken.php';
require_once $root.'/application/common/util/ApiV1Bootstrap.php';

use app\common\util\ApiV1AccessToken;
use app\common\util\ApiV1RefreshToken;
use app\common\util\ApiV1Bootstrap;

$secret = str_repeat('s', 32);
$sessionId = str_repeat('a', 32);
$jwt = ApiV1AccessToken::issue(42, $sessionId, $secret, 900, 1000);
$claims = ApiV1AccessToken::verify($jwt, $secret, 1001);
authSame('42', $claims['sub'], 'Access token subject mismatch.');
authSame($sessionId, $claims['sid'], 'Access token session mismatch.');
authSame('maccms-api-v1', $claims['aud'], 'Access token audience mismatch.');
authSame(null, ApiV1AccessToken::verify($jwt, $secret, 1900), 'Expired token must fail.');
authSame(null, ApiV1AccessToken::verify($jwt.'x', $secret, 1001), 'Tampered token must fail.');
authSame($jwt, ApiV1AccessToken::bearer('Bearer '.$jwt), 'Bearer parsing mismatch.');

$refresh = ApiV1RefreshToken::generate();
authAssert(ApiV1RefreshToken::isValid($refresh), 'Generated refresh token is invalid.');
authSame(64, strlen(ApiV1RefreshToken::digest($refresh)), 'Refresh digest must be SHA-256 hex.');
authAssert(ApiV1RefreshToken::digest($refresh) !== $refresh, 'Refresh plaintext must not equal stored digest.');

$routes = array(
    array('POST','/api/v1/auth/login','/v1.auth/login'),
    array('POST','/api/v1/auth/refresh','/v1.auth/refresh'),
    array('POST','/api/v1/auth/logout','/v1.auth/logout'),
    array('GET','/api/v1/auth/sessions','/v1.auth/sessions'),
    array('DELETE','/api/v1/auth/sessions/'.str_repeat('b',32),'/v1.auth/revoke/session_id/'.str_repeat('b',32)),
);
foreach ($routes as $route) {
    $resolved = ApiV1Bootstrap::resolve(array('REQUEST_METHOD'=>$route[0],'REQUEST_URI'=>$route[1],'SCRIPT_NAME'=>'/index.php'));
    authSame($route[2], $resolved['path_info'], 'Route mismatch for '.$route[0].' '.$route[1]);
}
$wrong = ApiV1Bootstrap::resolve(array('REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/api/v1/auth/login','SCRIPT_NAME'=>'/index.php'));
authSame('/v1.index/methodNotAllowed', $wrong['path_info'], 'Login must reject GET.');

$migration = file_get_contents($root.'/application/data/migrations/20260919000500_api_refresh_sessions.sql');
foreach (array('UNIQUE KEY `uk_api_refresh_token_hash`','`token_hash` char(64)','`consumed_at`','`revoked_at`','ENGINE=InnoDB') as $needle) {
    authAssert(strpos($migration, $needle) !== false, 'Migration missing '.$needle);
}
$service = file_get_contents($root.'/application/common/util/ApiV1SessionService.php');
authAssert(strpos($service, "where('token_hash', \$hash)->lock(true)") !== false, 'Rotation must lock the presented token.');
authAssert(strpos($service, "'revoke_reason'=>'replay'") !== false, 'Replay must revoke the family.');
authAssert(strpos($service, "'refresh_token'=>\$next") !== false, 'Rotation must return only the successor plaintext.');
authAssert(strpos($service, "'token_hash'=>ApiV1RefreshToken::digest(\$token)") !== false, 'Storage must use the digest.');

fwrite(STDOUT, "API v1 session security contract passed.".PHP_EOL);
