<?php
namespace app\common\behavior;

use app\common\util\ApiV1Bootstrap;
use app\common\util\ApiV1CorsPolicy;
use app\common\util\ApiV1EndpointPolicy;
use app\common\util\ApiV1RateLimiter;
use app\common\util\ApiV1RequestId;
use app\common\util\ApiV1Response;
use InvalidArgumentException;

final class ApiV1DeliveryPolicy
{
    public function run(&$params)
    {
        if (!defined('MAC_API_V1_REQUEST') || !MAC_API_V1_REQUEST) return;

        $method=strtoupper(isset($_SERVER['REQUEST_METHOD'])?(string)$_SERVER['REQUEST_METHOD']:'GET');
        $path=ApiV1Bootstrap::requestPath($_SERVER);
        $path=is_string($path)?$path:'/api/v1';
        $requestedMethod=$method==='OPTIONS'
            ? strtoupper(isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])?(string)$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']:'')
            : $method;
        $policy=ApiV1EndpointPolicy::resolve($requestedMethod,$path);
        $origin=isset($_SERVER['HTTP_ORIGIN'])?trim((string)$_SERVER['HTTP_ORIGIN']):'';
        $corsHeaders=array();

        if ($origin!=='') {
            try {
                $cors=new ApiV1CorsPolicy($this->origins());
                $corsHeaders=$method==='OPTIONS'
                    ?$cors->preflight($origin,$requestedMethod,$policy['methods'])
                    :$cors->headers($origin,$method,$policy['methods']);
            } catch(InvalidArgumentException $exception) {
                $this->deny(403,'CORS_ORIGIN_DENIED','The request origin is not allowed.',array());
            }
            foreach($corsHeaders as $name=>$value) header($name.': '.$value);
        }

        if ($method==='OPTIONS') {
            if ($origin==='') $this->deny(400,'CORS_PREFLIGHT_INVALID','The preflight request is invalid.',array());
            http_response_code(204);
            exit;
        }

        $rate=$this->rate($policy);
        $limiter=new ApiV1RateLimiter(RUNTIME_PATH.'api_v1_rate');
        $client=isset($_SERVER['REMOTE_ADDR'])?(string)$_SERVER['REMOTE_ADDR']:'unknown';
        $result=$limiter->consume($policy['bucket'],$client,$rate['limit'],$rate['window']);
        header('X-RateLimit-Limit: '.$rate['limit']);
        header('X-RateLimit-Remaining: '.$result['remaining']);
        if (!$result['allowed']) {
            $headers=$corsHeaders;
            $headers['Retry-After']=(string)$result['retry_after'];
            $this->deny(!empty($result['unavailable'])?503:429,!empty($result['unavailable'])?'RATE_LIMIT_UNAVAILABLE':'RATE_LIMITED',!empty($result['unavailable'])?'Request limiting is temporarily unavailable.':'Too many requests.',$headers);
        }
    }

    private function origins()
    {
        $raw=isset($GLOBALS['config']['app']['api_v1_cors_origins'])
            ?$GLOBALS['config']['app']['api_v1_cors_origins']:array();
        if (is_string($raw)) $raw=preg_split('/\\s*,\\s*/',trim($raw),-1,PREG_SPLIT_NO_EMPTY);
        return is_array($raw)?array_values($raw):array();
    }

    private function rate(array $policy)
    {
        $limit=(int)$policy['limit'];
        $window=(int)$policy['window'];
        $all=isset($GLOBALS['config']['app']['api_v1_rate_limits'])&&is_array($GLOBALS['config']['app']['api_v1_rate_limits'])
            ?$GLOBALS['config']['app']['api_v1_rate_limits']:array();
        $cfg=isset($all[$policy['bucket']])&&is_array($all[$policy['bucket']])?$all[$policy['bucket']]:array();
        if (isset($cfg['limit'])) $limit=max(1,min(10000,(int)$cfg['limit']));
        if (isset($cfg['window'])) $window=max(1,min(3600,(int)$cfg['window']));
        return array('limit'=>$limit,'window'=>$window);
    }

    private function deny($status,$code,$message,array $headers)
    {
        $requestId=ApiV1RequestId::resolve(isset($_SERVER['HTTP_X_REQUEST_ID'])?(string)$_SERVER['HTTP_X_REQUEST_ID']:null);
        $headers=array_merge(array(
            'Content-Type'=>'application/json; charset=utf-8',
            'X-Request-ID'=>$requestId,
            'Cache-Control'=>'no-store',
        ),$headers);
        http_response_code((int)$status);
        foreach($headers as $name=>$value) header($name.': '.$value);
        echo json_encode(ApiV1Response::error($code,$message,$requestId),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        exit;
    }
}
