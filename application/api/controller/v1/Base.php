<?php
namespace app\api\controller\v1;

use app\common\util\ApiV1Pagination;
use app\common\util\ApiV1Locale;
use app\common\util\ApiV1RequestId;
use app\common\util\ApiV1Response;
use app\common\util\ApiV1Etag;
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

    protected function collectionResponse(array $items, ApiV1Pagination $pagination, $totalItems, Request $request = null, array $meta = array())
    {
        $requestId = $this->requestId($request);
        return $this->jsonResponse(ApiV1Response::collection($items, $pagination, $totalItems, $requestId, $meta), 200, $requestId);
    }

    protected function cacheableResponse($data, Request $request = null, array $meta = array())
    {
        $requestId=$this->requestId($request);
        return $this->cacheableJsonResponse(ApiV1Response::success($data,$requestId,$meta),$request,$requestId);
    }

    protected function cacheableCollectionResponse(array $items,ApiV1Pagination $pagination,$totalItems,Request $request=null,array $meta=array())
    {
        $requestId=$this->requestId($request);
        return $this->cacheableJsonResponse(ApiV1Response::collection($items,$pagination,$totalItems,$requestId,$meta),$request,$requestId);
    }

    protected function cacheableRawDocumentResponse($document,Request $request)
    {
        $document=(string)$document;
        $requestId=$this->requestId($request);
        $etag=ApiV1Etag::make($document);
        $headers=array(
            'Content-Type'=>'application/json; charset=utf-8',
            'X-Request-ID'=>$requestId,
            'ETag'=>$etag,
            'Cache-Control'=>'public, no-cache, must-revalidate',
            'Vary'=>'Origin',
        );
        if (ApiV1Etag::matches($request->header('If-None-Match'),$etag)) return response('',304,$headers);
        return response($document,200,$headers);
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

    protected function canonicalRedirectResponse($canonicalPublicId, $location, ApiV1Locale $locale, Request $request = null)
    {
        $requestId = $this->requestId($request);
        return json(
            ApiV1Response::success(array('canonical_public_id'=>(string)$canonicalPublicId), $requestId, array('locale'=>$locale->code())),
            308,
            array('Content-Type'=>'application/json; charset=utf-8','X-Request-ID'=>$requestId,'Location'=>(string)$location)
        );
    }

    protected function internalError(Request $request = null)
    {
        return $this->errorResponse('INTERNAL_ERROR', 'An internal error occurred.', 500, $request);
    }

    private function cacheableJsonResponse(array $payload,Request $request,$requestId)
    {
        $validator=$payload;
        if (isset($validator['meta']['request_id'])) unset($validator['meta']['request_id']);
        $etag=ApiV1Etag::make($validator);
        $headers=array(
            'Content-Type'=>'application/json; charset=utf-8',
            'X-Request-ID'=>$requestId,
            'ETag'=>$etag,
            'Cache-Control'=>'public, no-cache, must-revalidate',
            'Vary'=>'Accept-Language, Origin',
        );
        if ($request!==null && ApiV1Etag::matches($request->header('If-None-Match'),$etag)) {
            return response('',304,$headers);
        }
        return json($payload,200,$headers);
    }

    private function jsonResponse(array $payload, $status, $requestId)
    {
        return json($payload, $status, array(
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Request-ID' => $requestId,
        ));
    }
}
