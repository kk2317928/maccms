<?php
namespace app\api\controller\v1;

use app\common\util\ApiV1Pagination;
use app\common\util\ApiV1RequestId;
use app\common\util\ApiV1Response;
use InvalidArgumentException;
use think\Request;
use Throwable;

class Base extends \app\api\controller\Base
{
    protected function requestId(Request $request = null)
    {
        $candidate = $request === null ? null : $request->header('X-Request-ID');
        return ApiV1RequestId::resolve($candidate);
    }

    protected function successResponse($data, Request $request = null, array $meta = array(), $status = 200)
    {
        $requestId = $this->requestId($request);
        return $this->jsonResponse(ApiV1Response::success($data, $requestId, $meta), $status, $requestId);
    }

    protected function collectionResponse(array $items, ApiV1Pagination $pagination, $totalItems, Request $request = null)
    {
        $requestId = $this->requestId($request);
        return $this->jsonResponse(ApiV1Response::collection($items, $pagination, $totalItems, $requestId), 200, $requestId);
    }

    protected function errorResponse($code, $message, $status, Request $request = null, array $details = array())
    {
        $requestId = $this->requestId($request);
        return $this->jsonResponse(ApiV1Response::error($code, $message, $requestId, $details), $status, $requestId);
    }

    protected function pagination(Request $request)
    {
        try {
            return ApiV1Pagination::fromQuery($request->get());
        } catch (InvalidArgumentException $exception) {
            return $this->errorResponse(
                'VALIDATION_ERROR',
                'The request parameters are invalid.',
                422,
                $request,
                array($exception->getMessage() => 'invalid')
            );
        }
    }

    protected function internalError(Request $request = null)
    {
        return $this->errorResponse('INTERNAL_ERROR', 'An internal error occurred.', 500, $request);
    }

    private function jsonResponse(array $payload, $status, $requestId)
    {
        return json($payload, $status, array(
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Request-ID' => $requestId,
        ));
    }
}
