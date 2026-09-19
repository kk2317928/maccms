<?php
namespace app\common\exception;

use app\common\util\ApiV1RequestId;
use app\common\util\ApiV1Response;
use Exception;
use think\Log;
use think\Request;
use think\Response;
use think\exception\Handle;

class ApiV1ExceptionHandler extends Handle
{
    private $requestId;

    public function report(Exception $exception)
    {
        Log::record('[API_V1_INTERNAL_ERROR] request_id=' . $this->requestId(), 'error');
    }

    public function render(Exception $exception)
    {
        $requestId = $this->requestId();
        return Response::create(
            ApiV1Response::error('INTERNAL_ERROR', 'An internal error occurred.', $requestId),
            'json',
            500,
            array(
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Request-ID' => $requestId,
            )
        );
    }

    private function requestId()
    {
        if ($this->requestId === null) {
            $candidate = Request::instance()->header('X-Request-ID');
            $this->requestId = ApiV1RequestId::resolve($candidate);
        }
        return $this->requestId;
    }
}
