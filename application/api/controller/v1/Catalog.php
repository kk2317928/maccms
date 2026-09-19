<?php
namespace app\api\controller\v1;

use app\common\util\ApiV1CatalogQuery;
use app\common\util\ApiV1CatalogService;
use app\common\util\ApiV1Pagination;
use InvalidArgumentException;
use think\Request;

class Catalog extends Base
{
    private function service() { return new ApiV1CatalogService(); }

    public function home(Request $request)
    {
        return $this->successResponse($this->service()->home(), $request);
    }

    public function videos(Request $request)
    {
        try {
            $pagination = ApiV1Pagination::fromQuery($request->get());
            $result = $this->service()->videos($pagination, ApiV1CatalogQuery::fromArray($request->get()));
            return $this->collectionResponse($result['items'], $pagination, $result['total'], $request);
        } catch (InvalidArgumentException $exception) { return $this->validation($exception, $request); }
    }

    public function search(Request $request)
    {
        try {
            $pagination = ApiV1Pagination::fromQuery($request->get());
            $query = ApiV1CatalogQuery::fromArray($request->get());
            $result = $this->service()->search($pagination, $query, ApiV1CatalogQuery::searchTerm($request->get()));
            return $this->collectionResponse($result['items'], $pagination, $result['total'], $request);
        } catch (InvalidArgumentException $exception) { return $this->validation($exception, $request); }
    }

    public function detail(Request $request, $public_id)
    {
        $data = $this->service()->detail($public_id);
        return $data === null ? $this->errorResponse('NOT_FOUND','The requested resource was not found.',404,$request) : $this->successResponse($data,$request);
    }

    public function episodes(Request $request, $public_id)
    {
        $data = $this->service()->episodes($public_id);
        return $data === null ? $this->errorResponse('NOT_FOUND','The requested resource was not found.',404,$request) : $this->successResponse($data,$request);
    }

    public function taxonomies(Request $request)
    {
        return $this->successResponse($this->service()->taxonomies(), $request);
    }

    private function validation(InvalidArgumentException $exception, Request $request)
    {
        return $this->errorResponse('VALIDATION_ERROR','The request parameters are invalid.',422,$request,array($exception->getMessage()=>'invalid'));
    }
}
