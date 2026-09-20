<?php
namespace app\api\controller\v1;

use app\common\util\ApiV1Locale;
use app\common\util\ApiV1PlaybackService;
use InvalidArgumentException;
use think\Request;
use Throwable;

final class Playback extends Base
{
    public function resolve(Request $request,$public_id,$source_id,$episode_id)
    {
        try {
            $service=new ApiV1PlaybackService();
            $state=$service->canonicalResource($public_id);
            if ($state['status']==='not_found') return $this->errorResponse('NOT_FOUND','The requested resource was not found.',404,$request);
            if ($state['status']==='redirect') {
                $locale=ApiV1Locale::resolve($request->get(),$request->header('Accept-Language'));
                $location='/api/v1/videos/'.rawurlencode($state['canonical_public_id']).'/playback/'.rawurlencode($source_id).'/'.rawurlencode($episode_id);
                return $this->canonicalRedirectResponse($state['canonical_public_id'],$location,$locale,$request);
            }
            $data=$service->resolve($state['canonical_public_id'],$source_id,$episode_id,$request->get());
            return $data===null
                ?$this->errorResponse('NOT_FOUND','The requested resource was not found.',404,$request)
                :$this->successResponse($data->toArray(),$request);
        } catch(InvalidArgumentException $e) {
            return $this->errorResponse('PLAYBACK_UNAVAILABLE','The requested playback source is unavailable.',403,$request);
        } catch(Throwable $e) { return $this->internalError($request); }
    }
}
