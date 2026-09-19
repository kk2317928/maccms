<?php
namespace app\api\controller\v1;

use app\common\util\ApiV1AccessToken;
use app\common\util\ApiV1SessionService;
use think\Db;
use think\Request;

class Auth extends Base
{
    public function login(Request $request)
    {
        $input = $request->post();
        $name = isset($input['user_name']) && is_string($input['user_name']) ? trim($input['user_name']) : '';
        $password = isset($input['user_pwd']) && is_string($input['user_pwd']) ? $input['user_pwd'] : '';
        if ($name === '' || $password === '') {
            return $this->errorResponse('VALIDATION_ERROR', 'The request parameters are invalid.', 422, $request);
        }
        if ($this->secret() === '') { return $this->internalError($request); }
        $result = model('User')->login(array(
            'user_name'=>$name, 'user_pwd'=>$password, 'verify'=>'', 'openid'=>'', 'col'=>'',
        ), array('set_cookie'=>false,'return_meta'=>true));
        if ((int)($result['code'] ?? 0) !== 1 || empty($result['meta']['user_id'])) {
            return $this->errorResponse('INVALID_CREDENTIALS', 'The credentials are invalid.', 401, $request);
        }
        $session = (new ApiV1SessionService())->create(
            (int)$result['meta']['user_id'], $input['device_name'] ?? '', $request->header('User-Agent'), $request->ip()
        );
        return $this->tokenResponse($this->tokenPayload((int)$result['meta']['user_id'], $session), $request, 201);
    }

    public function refresh(Request $request)
    {
        if ($this->secret() === '') { return $this->internalError($request); }
        $input = $request->post();
        $token = isset($input['refresh_token']) && is_string($input['refresh_token']) ? $input['refresh_token'] : '';
        $result = (new ApiV1SessionService())->rotate($token, $request->header('User-Agent'), $request->ip());
        if (($result['status'] ?? '') === 'replayed') {
            return $this->errorResponse('REFRESH_TOKEN_REPLAYED', 'The session has been revoked.', 401, $request);
        }
        if (($result['status'] ?? '') !== 'ok') {
            return $this->errorResponse('INVALID_REFRESH_TOKEN', 'The refresh token is invalid.', 401, $request);
        }
        $user = Db::name('user')->field('user_id')->where(array('user_id'=>$result['user_id'],'user_status'=>1))->find();
        if (empty($user)) {
            (new ApiV1SessionService())->revokeFamily($result['user_id'], $result['session_id'], 'account');
            return $this->errorResponse('INVALID_REFRESH_TOKEN', 'The refresh token is invalid.', 401, $request);
        }
        return $this->tokenResponse($this->tokenPayload($result['user_id'], $result), $request);
    }

    public function logout(Request $request)
    {
        $claims = $this->claims($request);
        if ($claims === null) { return $this->errorResponse('UNAUTHENTICATED', 'Authentication is required.', 401, $request); }
        (new ApiV1SessionService())->revokeFamily((int)$claims['sub'], $claims['sid'], 'logout');
        return $this->successResponse(array('revoked'=>true), $request);
    }

    public function sessions(Request $request)
    {
        $claims = $this->claims($request);
        if ($claims === null) { return $this->errorResponse('UNAUTHENTICATED', 'Authentication is required.', 401, $request); }
        return $this->successResponse((new ApiV1SessionService())->listForUser((int)$claims['sub']), $request);
    }

    public function revoke(Request $request, $session_id = '')
    {
        $claims = $this->claims($request);
        if ($claims === null) { return $this->errorResponse('UNAUTHENTICATED', 'Authentication is required.', 401, $request); }
        if (preg_match('/\A[a-f0-9]{32}\z/D', (string)$session_id) !== 1) {
            return $this->errorResponse('VALIDATION_ERROR', 'The request parameters are invalid.', 422, $request);
        }
        (new ApiV1SessionService())->revokeFamily((int)$claims['sub'], $session_id, 'user');
        return $this->successResponse(array('revoked'=>true,'session_id'=>$session_id), $request);
    }

    private function tokenResponse(array $payload, Request $request, $status = 200)
    {
        $requestId = $this->requestId($request);
        return json(ApiV1Response::success($payload, $requestId), $status, array(
            'Content-Type'=>'application/json; charset=utf-8', 'X-Request-ID'=>$requestId,
            'Cache-Control'=>'no-store', 'Pragma'=>'no-cache',
        ));
    }

    private function tokenPayload($userId, array $session)
    {
        return array(
            'token_type'=>'Bearer',
            'access_token'=>ApiV1AccessToken::issue($userId, $session['session_id'], $this->secret(), 900),
            'expires_in'=>900,
            'refresh_token'=>$session['refresh_token'],
            'refresh_expires_at'=>(int)$session['expires_at'],
            'session_id'=>$session['session_id'],
        );
    }

    private function claims(Request $request)
    {
        $token = ApiV1AccessToken::bearer($request->header('Authorization'));
        $claims = $token === '' ? null : ApiV1AccessToken::verify($token, $this->secret());
        if ($claims === null || !(new ApiV1SessionService())->isActive((int)$claims['sub'], $claims['sid'])) {
            return null;
        }
        return $claims;
    }

    private function secret()
    {
        $app = isset($GLOBALS['config']['app']) && is_array($GLOBALS['config']['app']) ? $GLOBALS['config']['app'] : array();
        $secret = trim((string)($app['api_v1_jwt_secret'] ?? getenv('MACCMS_API_V1_JWT_SECRET')));
        return strlen($secret) >= 32 ? $secret : '';
    }
}
