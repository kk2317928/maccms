<?php
namespace app\api\controller\v1;

use app\common\util\ApiV1ActivityMerge;
use app\common\util\ApiV1ActivityRepository;
use app\common\util\ApiV1AuthContext;
use app\common\util\ApiV1Locale;
use app\common\util\ApiV1Pagination;
use InvalidArgumentException;
use think\Request;
use Throwable;

class Activity extends Base
{
    private function repository() { return new ApiV1ActivityRepository(); }

    public function favorites(Request $request)
    {
        $userId=$this->userId($request); if ($userId===null) return $this->unauthorized($request);
        try {
            $pagination=ApiV1Pagination::fromQuery($request->get()); $locale=$this->locale($request);
            $result=$this->repository()->favorites($userId,$pagination,$locale);
            return $this->collectionResponse($result['items'],$pagination,$result['total'],$request,array('locale'=>$locale->code()));
        } catch(InvalidArgumentException $e) { return $this->validation($e,$request); }
        catch(Throwable $e) { return $this->internalError($request); }
    }

    public function favorite(Request $request,$public_id)
    {
        $userId=$this->userId($request); if ($userId===null) return $this->unauthorized($request);
        try {
            $id=$this->repository()->favorite($userId,$public_id);
            return $id===null?$this->notFound($request):$this->successResponse(array('public_id'=>$id,'favorited'=>true),$request);
        } catch(Throwable $e) { return $this->internalError($request); }
    }

    public function unfavorite(Request $request,$public_id)
    {
        $userId=$this->userId($request); if ($userId===null) return $this->unauthorized($request);
        try {
            $id=$this->repository()->unfavorite($userId,$public_id);
            return $id===null?$this->notFound($request):$this->successResponse(array('public_id'=>$id,'favorited'=>false),$request);
        } catch(Throwable $e) { return $this->internalError($request); }
    }

    public function history(Request $request)
    {
        $userId=$this->userId($request); if ($userId===null) return $this->unauthorized($request);
        try {
            $pagination=ApiV1Pagination::fromQuery($request->get()); $locale=$this->locale($request);
            $result=$this->repository()->history($userId,$pagination,$locale);
            return $this->collectionResponse($result['items'],$pagination,$result['total'],$request,array('locale'=>$locale->code()));
        } catch(InvalidArgumentException $e) { return $this->validation($e,$request); }
        catch(Throwable $e) { return $this->internalError($request); }
    }

    public function progress(Request $request,$public_id)
    {
        $userId=$this->userId($request); if ($userId===null) return $this->unauthorized($request);
        try {
            $data=$this->repository()->progress($userId,$public_id);
            return $data===null?$this->notFound($request):$this->successResponse($data,$request);
        } catch(Throwable $e) { return $this->internalError($request); }
    }

    public function saveProgress(Request $request,$public_id)
    {
        $userId=$this->userId($request); if ($userId===null) return $this->unauthorized($request);
        try {
            $data=$this->repository()->saveProgress($userId,$public_id,$request->param());
            return $data===null?$this->notFound($request):$this->successResponse($data,$request);
        } catch(InvalidArgumentException $e) { return $this->validation($e,$request); }
        catch(Throwable $e) { return $this->internalError($request); }
    }

    public function deleteProgress(Request $request,$public_id)
    {
        $userId=$this->userId($request); if ($userId===null) return $this->unauthorized($request);
        try {
            $id=$this->repository()->deleteProgress($userId,$public_id);
            return $id===null?$this->notFound($request):$this->successResponse(array('public_id'=>$id,'deleted'=>true),$request);
        } catch(Throwable $e) { return $this->internalError($request); }
    }

    public function merge(Request $request)
    {
        $userId=$this->userId($request); if ($userId===null) return $this->unauthorized($request);
        try {
            $payload=ApiV1ActivityMerge::normalize($request->param());
            return $this->successResponse($this->repository()->merge($userId,$payload),$request);
        } catch(InvalidArgumentException $e) { return $this->validation($e,$request); }
        catch(Throwable $e) { return $this->internalError($request); }
    }

    private function userId(Request $request) { return ApiV1AuthContext::userId($request); }
    private function locale(Request $request) { return ApiV1Locale::resolve($request->get(),$request->header('Accept-Language')); }
    private function unauthorized(Request $request) { return $this->errorResponse('UNAUTHENTICATED','Authentication is required.',401,$request); }
    private function notFound(Request $request) { return $this->errorResponse('NOT_FOUND','The requested resource was not found.',404,$request); }
    private function validation(InvalidArgumentException $e,Request $request) { return $this->errorResponse('VALIDATION_ERROR','The request parameters are invalid.',422,$request,array($e->getMessage()=>'invalid')); }
}
