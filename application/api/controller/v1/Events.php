<?php
namespace app\api\controller\v1;

use app\common\util\ApiV1AuthContext;
use app\common\util\ApiV1EventContract;
use app\common\util\ApiV1EventRepository;
use app\common\util\VideoEventPolicy;
use InvalidArgumentException;
use think\Request;
use Throwable;

class Events extends Base
{
    public function ingest(Request $request)
    {
        try {
            $payload=$request->param();
            $items=isset($payload['events'])?$payload['events']:[$payload];
            $policy=new VideoEventPolicy();
            if(!is_array($items) || !$policy->allowBatch(count($items)))return $this->errorResponse('VALIDATION_ERROR','The request parameters are invalid.',422,$request);
            $claims=ApiV1AuthContext::claims($request);
            $userId=$claims===null?0:(int)$claims['sub'];
            $session=$claims===null?(string)$request->header('X-Session-ID'):(string)$claims['sid'];
            $actor=ApiV1EventContract::actorKey($userId,$session,(string)$request->header('X-Device-ID'));
            $repo=new ApiV1EventRepository();$now=time();$normalized=[];
            foreach($items as $input){
                if(!is_array($input))throw new InvalidArgumentException('event');
                $event=ApiV1EventContract::normalize($input);
                if(!$policy->acceptsOccurredAt($event['occurred_at'],$now))throw new InvalidArgumentException('occurred_at');
                $normalized[]=$event;
            }
            $result=$repo->ingestBatch($normalized,$actor,$userId,$now,$policy);
            if(!empty($result['rate_limited']))return $this->errorResponse('RATE_LIMITED','Too many requests.',429,$request);
            return $this->successResponse(['accepted'=>$result['accepted'],'duplicates'=>$result['duplicates']],$request,[],202);
        } catch(InvalidArgumentException $e){return $this->errorResponse('VALIDATION_ERROR','The request parameters are invalid.',422,$request,[$e->getMessage()=>'invalid']);}
        catch(Throwable $e){return $this->internalError($request);}
    }
}
