<?php
namespace app\api\controller\v1;

use app\common\util\ApiV1DiscoveryRepository;
use InvalidArgumentException;
use think\Request;
use Throwable;

class Discovery extends Base
{
    public function rankings(Request $request)
    {
        try {
            $window=(string)$request->get('window','days_7');$limit=(int)$request->get('limit',20);
            return $this->cacheableResponse(['window'=>$window,'items'=>(new ApiV1DiscoveryRepository())->rankings($window,$limit)],$request);
        } catch(InvalidArgumentException $e){return $this->errorResponse('VALIDATION_ERROR','The request parameters are invalid.',422,$request,[$e->getMessage()=>'invalid']);}
        catch(Throwable $e){return $this->internalError($request);}
    }

    public function recommendations(Request $request,$public_id)
    {
        try {
            $items=(new ApiV1DiscoveryRepository())->recommendations($public_id,(int)$request->get('limit',12));
            return $items===null?$this->errorResponse('NOT_FOUND','The requested resource was not found.',404,$request):$this->cacheableResponse(['public_id'=>strtoupper((string)$public_id),'items'=>$items],$request);
        } catch(Throwable $e){return $this->internalError($request);}
    }
}
