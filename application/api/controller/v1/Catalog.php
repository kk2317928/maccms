<?php
namespace app\api\controller\v1;

use app\common\util\ApiV1CatalogQuery;
use app\common\util\ApiV1CatalogService;
use app\common\util\ApiV1Locale;
use app\common\util\ApiV1Pagination;
use InvalidArgumentException;
use think\Request;

class Catalog extends Base
{
    private function service() { return new ApiV1CatalogService(); }

    public function home(Request $request)
    {
        try {
            $locale=$this->locale($request);
            return $this->successResponse($this->service()->home($locale),$request,array('locale'=>$locale->code()));
        } catch(InvalidArgumentException $exception) { return $this->validation($exception,$request); }
    }

    public function videos(Request $request)
    {
        try {
            $locale=$this->locale($request);
            $pagination=ApiV1Pagination::fromQuery($request->get());
            $result=$this->service()->videos($pagination,ApiV1CatalogQuery::fromArray($request->get()),$locale);
            return $this->collectionResponse($result['items'],$pagination,$result['total'],$request,array('locale'=>$locale->code()));
        } catch(InvalidArgumentException $exception) { return $this->validation($exception,$request); }
    }

    public function search(Request $request)
    {
        try {
            $locale=$this->locale($request);
            $pagination=ApiV1Pagination::fromQuery($request->get());
            $query=ApiV1CatalogQuery::fromArray($request->get());
            $result=$this->service()->search($pagination,$query,ApiV1CatalogQuery::searchTerm($request->get()),$locale);
            return $this->collectionResponse($result['items'],$pagination,$result['total'],$request,array('locale'=>$locale->code()));
        } catch(InvalidArgumentException $exception) { return $this->validation($exception,$request); }
    }

    public function detail(Request $request,$public_id)
    {
        try {
            $locale=$this->locale($request);
            $state=$this->service()->canonicalResource($public_id);
            if ($state['status']==='not_found') return $this->notFound($request);
            if ($state['status']==='redirect') {
                return $this->canonicalRedirectResponse($state['canonical_public_id'],$this->canonicalLocation($state['canonical_public_id'],false,$locale),$locale,$request);
            }
            $data=$this->service()->detail($state['canonical_public_id'],$locale);
            return $data===null?$this->notFound($request):$this->successResponse($data,$request,array('locale'=>$locale->code()));
        } catch(InvalidArgumentException $exception) { return $this->validation($exception,$request); }
    }

    public function episodes(Request $request,$public_id)
    {
        try {
            $locale=$this->locale($request);
            $state=$this->service()->canonicalResource($public_id);
            if ($state['status']==='not_found') return $this->notFound($request);
            if ($state['status']==='redirect') {
                return $this->canonicalRedirectResponse($state['canonical_public_id'],$this->canonicalLocation($state['canonical_public_id'],true,$locale),$locale,$request);
            }
            $data=$this->service()->episodes($state['canonical_public_id']);
            return $data===null?$this->notFound($request):$this->successResponse($data,$request,array('locale'=>$locale->code()));
        } catch(InvalidArgumentException $exception) { return $this->validation($exception,$request); }
    }

    public function taxonomies(Request $request)
    {
        try {
            $locale=$this->locale($request);
            return $this->successResponse($this->service()->taxonomies($locale),$request,array('locale'=>$locale->code()));
        } catch(InvalidArgumentException $exception) { return $this->validation($exception,$request); }
    }

    private function locale(Request $request)
    {
        return ApiV1Locale::resolve($request->get(),$request->header('Accept-Language'));
    }

    private function canonicalLocation($publicId,$episodes,ApiV1Locale $locale)
    {
        return '/api/v1/videos/'.rawurlencode((string)$publicId).($episodes?'/episodes':'').'?locale='.rawurlencode($locale->code());
    }

    private function notFound(Request $request)
    {
        return $this->errorResponse('NOT_FOUND','The requested resource was not found.',404,$request);
    }

    private function validation(InvalidArgumentException $exception,Request $request)
    {
        return $this->errorResponse('VALIDATION_ERROR','The request parameters are invalid.',422,$request,array($exception->getMessage()=>'invalid'));
    }
}
